<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\ClinicType;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Reception\ReceptionCheckIn;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicActions;
use App\Support\ClinicClock;
use App\Support\ClinicNavigation;
use App\Support\Dashboard\DashboardWidgets;
use App\UserType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Clinic Portal's dynamic navigation, quick actions and dashboard: one
 * resolver (`ClinicAccess`) behind three registries, decided on the server.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create(['clinic_type' => ClinicType::DentalClinic]);
    $this->clinic = Clinic::factory()->create(['slug' => 'dynamic-dental', 'organization_id' => $this->organization->id]);
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id, 'is_main' => true, 'status' => BranchStatus::Active, 'name' => 'Main']);
});

/**
 * @param  list<ClinicRole>  $roles
 * @return array{user: User, membership: ClinicMembership}
 */
function dynamicMember(bool $owner = false, array $roles = [], bool $allBranches = true, ?Branch $branch = null, bool $practitioner = false): array
{
    $test = test();
    $branch ??= $test->branch;
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $test->organization->id, 'password' => bcrypt('dynamic-pass')]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $test->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => $owner,
        'has_all_branches_access' => $allBranches, 'status' => ClinicMembershipStatus::Active,
    ]);

    if ($roles !== []) {
        setPermissionsTeamId(null);
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
                ->syncPermissions(collect($role->permissions())
                    ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                    ->all());
        }
        app(ClinicRoleAssignmentService::class)->syncRoles($user, array_map(fn (ClinicRole $role) => $role->value, $roles), [$branch]);
    }

    if ($practitioner) {
        $employee = ClinicEmployee::create(['clinic_id' => $test->clinic->id, 'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => 'active']);
        Practitioner::create(['clinic_id' => $test->clinic->id, 'organization_id' => $test->organization->id, 'clinic_employee_id' => $employee->id, 'specialty' => 'Dentist', 'is_active' => true]);
    }

    return ['user' => $user, 'membership' => $membership];
}

function actAsDynamic(array $member, ?Branch $branch = null)
{
    return test()->actingAs($member['user'])->withSession(array_filter([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $member['membership']->id,
        ClinicContext::SESSION_BRANCH_ID => ($branch ?? test()->branch)?->id,
    ]));
}

/**
 * @param  list<string>  $modules
 */
function dynamicPlan(array $modules): void
{
    $plan = CompanyPlan::factory()->create();
    $plan->features()->sync(collect($modules)->map(fn (string $key) => (CompanyFeature::where('key', $key)->first() ?? CompanyFeature::factory()->create(['key' => $key]))->id)->all());
    CompanySubscription::factory()->create(['clinic_id' => test()->clinic->id, 'company_plan_id' => $plan->id]);
}

/**
 * Pages that are built later, registered here so the resolver's other rules
 * (plan, capability, specialty) can be seen deciding.
 *
 * @param  list<string>  $names
 */
function registerFutureRoutes(array $names): void
{
    foreach ($names as $name) {
        Route::middleware('web')->get('/clinic/__future/'.str_replace('.', '-', $name), fn () => 'later')->name($name);
    }
    app('router')->getRoutes()->refreshNameLookups();
}

/** @return array{navigation: list<string>, actions: list<string>, widgets: list<string>, dashboard_actions: list<string>} */
function dynamicSurface(AssertableInertia $page): array
{
    $props = $page->toArray()['props'];

    return [
        'navigation' => collect($props['clinic']['navigation'])->flatMap(fn (array $domain) => [$domain['key'], ...array_column($domain['children'], 'key')])->all(),
        'actions' => array_column($props['clinic']['actions'], 'key'),
        'widgets' => array_column($props['widgets'] ?? [], 'key'),
        // The dashboard's Quick Actions card: only actions whose destination works.
        'dashboard_actions' => array_column($props['actions'] ?? [], 'key'),
    ];
}

/** What a member's home (their workspace, through `/clinic/dashboard`) carries: navigation, actions, widgets. */
function dynamicDashboard(array $member, ?Branch $branch = null): array
{
    $surface = [];
    actAsDynamic($member, $branch)->followingRedirects()->get('/clinic/dashboard')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$surface) {
            $surface = dynamicSurface($page);
        });

    return $surface;
}

/** @return array<string, mixed> */
function dynamicWidgetData(array $member, ?Branch $branch = null): array
{
    $data = [];
    actAsDynamic($member, $branch)->get('/clinic/dashboard')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$data) {
            $data = $page->toArray()['props']['widgetData'];
        });

    return $data;
}

/*
 * Navigation
 */

it('builds navigation from permissions: an admin gets the clinic section, a receptionist does not', function () {
    $admin = dynamicDashboard(dynamicMember(roles: [ClinicRole::ClinicAdmin]));
    $receptionist = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]));

    expect($admin['navigation'])->toContain('staff', 'settings')
        ->and($receptionist['navigation'])->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'billing', 'follow_ups', 'notifications', 'support']);
});

it('leaves out a module the user has no permission for', function () {
    $member = dynamicMember(roles: [ClinicRole::BillingStaff]);

    expect(dynamicDashboard($member)['navigation'])->not->toContain('appointments')->not->toContain('administration');
});

it('leaves out a module the plan does not include, even for the owner', function () {
    registerFutureRoutes(['clinic.billing.index']);
    dynamicPlan(['patients', 'appointments']);

    expect(dynamicDashboard(dynamicMember(owner: true))['navigation'])->not->toContain('billing');
});

it('shows Billing once its page exists and the plan includes it', function () {
    registerFutureRoutes(['clinic.billing.index']);
    dynamicPlan(['patients', 'appointments', 'billing']);

    expect(dynamicDashboard(dynamicMember(owner: true))['navigation'])->toContain('billing');
});

it('lists Reports for whoever runs the clinic, and not for billing staff', function () {
    expect(Route::has('clinic.reports.index'))->toBeTrue()
        ->and(dynamicDashboard(dynamicMember(owner: true))['navigation'])->toContain('reports')
        ->and(dynamicDashboard(dynamicMember(roles: [ClinicRole::ClinicAdmin]))['navigation'])->toContain('reports')
        ->and(dynamicDashboard(dynamicMember(roles: [ClinicRole::BillingStaff]))['navigation'])->not->toContain('reports');

    actAsDynamic(dynamicMember(owner: true))->get('/clinic/reports')->assertOk();
});

it('leaves out a capability-gated action the plan does not switch on', function () {
    registerFutureRoutes(['clinic.orders.lab.create']);

    dynamicPlan(['patients', 'appointments']);
    expect(dynamicDashboard(dynamicMember(owner: true))['actions'])->not->toContain('new_lab_order');
});

it('shows a capability-gated action once the plan switches the capability on', function () {
    registerFutureRoutes(['clinic.orders.lab.create']);

    dynamicPlan(['patients', 'appointments', 'lab']);
    expect(dynamicDashboard(dynamicMember(owner: true))['actions'])->toContain('new_lab_order');
});

it('keeps Investigations open to clinicians whatever lab or imaging modules the plan has, reached from clinical work rather than the navigation', function () {
    dynamicPlan(['patients', 'appointments']);

    expect(dynamicDashboard(dynamicMember(owner: true))['navigation'])->not->toContain('orders');
    actAsDynamic(dynamicMember(owner: true))->get('/clinic/orders')->assertOk();
});

it('offers New Case and Repeat Case to every specialty, and New Assessment where physiotherapy is practised', function () {
    registerFutureRoutes(['clinic.physio.assessments.create']);
    $dental = dynamicDashboard(dynamicMember(owner: true))['actions'];

    $this->organization->update(['clinic_type' => ClinicType::Physiotherapy]);
    $physio = dynamicDashboard(dynamicMember(owner: true))['actions'];

    expect($dental)->toContain('new_case', 'repeat_case')->not->toContain('new_assessment')
        ->and($physio)->toContain('new_assessment', 'new_case', 'repeat_case');
});

it('resolves permissions at the active branch only', function () {
    $other = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id, 'status' => BranchStatus::Active]);
    $member = dynamicMember(roles: [ClinicRole::Receptionist], allBranches: false);
    $member['membership']->assignedBranches()->attach([$this->branch->id, $other->id]);

    expect(dynamicDashboard($member, $this->branch)['navigation'])->toContain('patients');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $member['user']->unsetRelation('roles')->unsetRelation('permissions');

    // The receptionist role was granted at the main branch only.
    expect(dynamicDashboard($member, $other)['navigation'])->toBe(['dashboard', 'notifications', 'support']);
});

it('shows owner-only navigation only while Owner Mode is on', function () {
    $owner = dynamicMember(owner: true);
    $ownerDomains = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'order' => 1, 'route' => 'clinic.dashboard'],
        ['key' => 'owner_console', 'label' => 'Owner console', 'icon' => 'owner', 'order' => 2, 'route' => 'clinic.dashboard', 'requires' => ['owner_mode' => true]],
    ];
    Route::middleware(['web', 'clinic_portal', 'clinic_context'])->get('/clinic/__nav-probe', fn () => response()->json(
        array_column(ClinicNavigation::for(app(ClinicAccess::class), [], $ownerDomains), 'key'),
    ));

    actAsDynamic($owner)->get('/clinic/__nav-probe')->assertExactJson(['dashboard']);

    $this->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->get('/clinic/__nav-probe')->assertExactJson(['dashboard', 'owner_console']);
});

it('never generates a More or Workspace entry, in the registry or for any user', function () {
    $labels = collect(ClinicNavigation::DOMAINS)->flatMap(fn (array $domain) => [$domain['label'], ...array_column($domain['children'] ?? [], 'label')]);
    expect($labels->map(fn ($label) => strtolower($label))->all())
        ->not->toContain('more', 'workspace', 'reception', 'visits', 'queue', 'check-in', 'walk-ins');

    $surface = dynamicDashboard(dynamicMember(owner: true));
    expect($surface['navigation'])->not->toContain('more', 'workspace', 'reception', 'visits');
});

it('sends one navigation list from the server, and the shell carries no destination list or route names of its own', function () {
    actAsDynamic(dynamicMember(owner: true))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clinic.navigation')
            ->has('clinic.actions')
            ->missing('clinic.mobile_navigation')
            ->missing('clinic.command_palette'));

    $sources = collect(glob(resource_path('js/clinic/shell/*.tsx')) ?: [])
        ->mapWithKeys(fn (string $file) => [basename($file) => file_get_contents($file)]);
    expect($sources)->not->toBeEmpty();

    foreach ($sources as $file => $source) {
        expect($source)->not->toMatch("/['\"]\\/clinic\\/(patients|appointments|reception|visits|admin)/")
            ->not->toMatch('/\broute\(\s*[\'"]clinic\./');
    }
});

it('offers the command palette exactly the actions the resolver allows', function () {
    $receptionist = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]));
    $nobody = dynamicDashboard(dynamicMember());

    // A receptionist takes payments, so Billing is one of their workspaces and Collect Payment one of their actions.
    expect($receptionist['actions'])->toBe(['new_case', 'repeat_case', 'new_appointment', 'collect_payment', 'request_leave'])
        ->and($nobody['actions'])->toBe([]);
});

/*
 * Dashboard
 */

it('keeps a receptionist on the Dashboard, composed of the front office', function () {
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);

    actAsDynamic($receptionist)->get('/clinic/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Dashboard', true));
    $surface = dynamicDashboard($receptionist);
    expect($surface['widgets'])->toContain('todays_patients', 'upcoming_appointments', 'waiting_now')->not->toContain('doctor_load', 'my_queue', 'month_calendar')
        // New Case, Repeat Case and booking; nothing that leads to an unbuilt page (Quick Billing, leave).
        ->and($surface['dashboard_actions'])->toBe(['new_case', 'repeat_case', 'new_appointment']);
});

it('resolves only the widgets a clinic administrator is eligible for, zone by zone and in order', function () {
    // Four figures at most, so Appointments gives way to Collections; no Owner Mode, so no money or security;
    // one branch, so no branch activity.
    expect(dynamicDashboard(dynamicMember(roles: [ClinicRole::ClinicAdmin]))['widgets'])->toBe([
        'patients_today', 'waiting_now', 'with_doctor_now', 'collections_today',
        'todays_patients', 'upcoming_appointments',
        'pending_payments',
        'needs_attention', 'follow_ups_due',
    ]);
});

it('neither queries nor sends billing figures to someone without billing access', function () {
    $member = dynamicMember(roles: [ClinicRole::ClinicAdmin]);
    // The administrator template includes billing; take it away at this branch.
    Role::findByName(ClinicRole::ClinicAdmin->value, 'web')->revokePermissionTo(['billing.view', 'billing.create', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = dynamicWidgetData($member);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($data)->toHaveKey('appointments_today')->not->toHaveKey('pending_payments')
        ->and(array_keys($data))->not->toContain('collections_today', 'cash_status')
        ->and(collect($data['needs_attention']['items'] ?? [])->pluck('id'))->not->toContain('pending_payments')
        ->and($queries)->not->toContain('patient_billing');
});

it('leaves billing widgets out when the plan has no billing', function () {
    dynamicPlan(['patients', 'appointments']);

    expect(dynamicDashboard(dynamicMember(owner: true))['widgets'])
        ->not->toContain('collections_today', 'cash_status');
});

it('names visits as cases only where the specialty registers cases', function () {
    $patient = Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Case', 'last_name' => 'Type', 'gender' => 'male', 'phone' => '9000000002', 'uhid' => 'UHID-DYN-2']);
    // A first check-in registers a new visit.
    app(ReceptionCheckIn::class)->checkIn($this->clinic, $this->branch, $patient);
    $owner = dynamicMember(owner: true);

    expect(dynamicWidgetData($owner)['todays_patients']['rows'][0]['kind_label'])->toBe('New case');

    $this->organization->update(['clinic_type' => ClinicType::Physiotherapy]);
    expect(dynamicWidgetData($owner)['todays_patients']['rows'][0]['kind_label'])->toBe('First visit');
});

it('counts only the active branch, and keeps clinic-wide widgets from branch-limited staff', function () {
    dynamicPlan(['patients', 'appointments', 'billing', 'xray']);
    $other = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id, 'status' => BranchStatus::Active]);
    $patient = Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Branch', 'last_name' => 'Patient', 'gender' => 'male', 'phone' => '9000000001', 'uhid' => 'UHID-DYN-1']);
    foreach ([$this->branch, $other, $other] as $branch) {
        Appointment::create(['clinic_id' => $this->clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'scheduled_at' => ClinicClock::now(), 'status' => 'scheduled']);
    }

    $member = dynamicMember(roles: [ClinicRole::ClinicAdmin], allBranches: false);
    $member['membership']->assignedBranches()->attach($this->branch->id);

    $widgets = dynamicDashboard($member)['widgets'];
    $data = dynamicWidgetData($member);

    expect($data['upcoming_appointments']['total'])->toBe(1)
        ->and($data['patients_today']['value'])->toBe(0)
        // Staff and branch overviews are clinic-wide.
        ->and($widgets)->not->toContain('staff_overview', 'branch_activity');
});

it('gives a multi-role user the union of their widgets, each once', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist, ClinicRole::Doctor], practitioner: true);

    // Every job, every workspace, on one Dashboard; each destination once.
    actAsDynamic($member)->get('/clinic/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/Dashboard', true));
    $workspaces = [];
    actAsDynamic($member)->get('/clinic/reception')->assertInertia(function (AssertableInertia $page) use (&$workspaces) {
        $workspaces = array_column($page->toArray()['props']['clinic']['workspaces'], 'key');
    });
    $navigation = dynamicDashboard($member)['navigation'];

    expect($workspaces)->toBe(['front_desk', 'today', 'billing'])
        ->and($navigation)->toContain('front_desk', 'billing', 'follow_ups')->not->toContain('new_case', 'repeat_case', 'consultations')
        ->and($navigation)->toBe(array_values(array_unique($navigation)));
});

it('adds owner widgets only while Owner Mode is on', function () {
    $owner = dynamicMember(owner: true);

    expect(dynamicDashboard($owner)['widgets'])->not->toContain('owner_finance', 'owner_security');

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $widgets = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$widgets) {
        $widgets = dynamicSurface($page)['widgets'];
    });

    // The plan is the sidebar's owner-only summary, never a second dashboard card.
    expect($widgets)->toContain('owner_finance', 'owner_security')->not->toContain('owner_plan');
});

it('keeps the plan in force inside Owner Mode', function () {
    dynamicPlan(['patients', 'appointments']);
    $owner = dynamicMember(owner: true);

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $widgets = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$widgets) {
        $widgets = dynamicSurface($page)['widgets'];
    });

    expect($widgets)->toContain('owner_security')->not->toContain('owner_finance', 'collections_today');
    expect(app(OwnerMode::class))->toBeInstanceOf(OwnerMode::class);
});

it('drives quick actions from permissions, and owner actions from Owner Mode', function () {
    $owner = dynamicMember(owner: true);
    // Staff is Administration, never a quick action.
    expect(dynamicDashboard($owner)['actions'])->toContain('new_case', 'new_appointment')->not->toContain('owner_settings', 'invite_staff');

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass']);
    $actions = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$actions) {
        $actions = dynamicSurface($page)['actions'];
    });
    expect($actions)->toContain('owner_settings');

    expect(dynamicDashboard(dynamicMember(roles: [ClinicRole::Doctor]))['actions'])->not->toContain('new_case', 'invite_staff');
});

it('has one dashboard engine and no role-specific dashboard', function () {
    $pages = collect(glob(resource_path('js/pages/ClinicPortal/*.tsx')))->map(fn (string $file) => basename($file));

    expect($pages->filter(fn (string $name) => preg_match('/(Reception|Doctor|Admin|Owner|Billing)Dashboard/', $name)))->toBeEmpty();

    // Every widget names its requirements; none is unconditional except quick actions.
    foreach (DashboardWidgets::WIDGETS as $widget) {
        expect($widget['kind'] === 'actions' || $widget['requires'] !== [])->toBeTrue();
    }
});

it('sends the dashboard figures with the first response, only as the page\'s own figures, never in the shared props', function () {
    $member = dynamicMember(roles: [ClinicRole::ClinicAdmin]);

    actAsDynamic($member)->get('/clinic/dashboard')
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            expect($props)->toHaveKey('widgetData')
                ->and(array_keys($props['clinic']))->not->toContain('metrics', 'widgets', 'widgetData', 'collections')
                ->and(json_encode($props['widgets']))->not->toMatch('/"(value|items|stats|total)":/');
        });
});

it('does not offer a fee on any action', function () {
    foreach (ClinicActions::ACTIONS as $action) {
        expect($action)->not->toHaveKey('fee')->not->toHaveKey('price');
    }
});

/*
 * Reception dashboard
 */

it('gives a receptionist without billing access a Front Desk with no billing section and runs no billing query', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist]);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(['billing.view', 'billing.create', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $billing = 'unset';
    actAsDynamic($member)->get('/clinic/reception')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$billing) {
        $billing = $page->toArray()['props']['billing'];
    });
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($billing)->toBeNull()
        ->and($queries)->not->toContain('patient_billing');
});

it('raises lab and imaging reports that are due as one attention item, only with the capability and permission', function () {
    DB::table('lab_orders')->insert([
        ['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Lab', 'last_name' => 'One', 'gender' => 'female', 'phone' => '9000000003', 'uhid' => 'UHID-DYN-3'])->id, 'lab_order_number' => 'L-1', 'status' => 'in_production', 'delivery_due_date' => ClinicClock::today()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    $attention = fn (array $member) => collect(dynamicWidgetData($member)['needs_attention']['items'] ?? [])->keyBy('id');
    dynamicPlan(['patients', 'appointments', 'billing']);
    $noCapability = $attention(dynamicMember(roles: [ClinicRole::ClinicAdmin]));

    CompanySubscription::query()->delete();
    dynamicPlan(['patients', 'appointments', 'billing', 'lab', 'xray']);
    $admin = dynamicMember(roles: [ClinicRole::ClinicAdmin]);
    $billingStaff = dynamicMember(roles: [ClinicRole::BillingStaff]);

    expect($noCapability->keys()->all())->not->toContain('reports_due')
        ->and($attention($admin)['reports_due']['count'])->toBe(1)
        // A summary, not a reporting widget of its own.
        ->and(dynamicDashboard($admin)['widgets'])->not->toContain('investigations')
        ->and($attention($billingStaff)->keys()->all())->not->toContain('reports_due');
});

it('lets the owner hide a widget from staff, but never show one they may not see', function () {
    // Since role homes the widget dashboard is the Command Center: an administrator's here.
    $owner = dynamicMember(owner: true);
    $receptionist = dynamicMember(roles: [ClinicRole::ClinicAdmin]);
    $restricted = Role::findByName(ClinicRole::ClinicAdmin->value, 'web');

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->put('/clinic/dashboard/visibility', ['role' => ClinicRole::ClinicAdmin->value, 'hidden' => ['follow_ups_due']])->assertRedirect();

    expect(dynamicDashboard($receptionist)['widgets'])->toContain('collections_today')->not->toContain('follow_ups_due');

    // Showing everything cannot give back what permissions take away. (The
    // owner's session moved to the receptionist above, which ended Owner Mode.)
    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->put('/clinic/dashboard/visibility', ['role' => ClinicRole::ClinicAdmin->value, 'hidden' => []])->assertRedirect();
    $restricted->revokePermissionTo(['billing.view', 'billing.create', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $receptionist['user']->unsetRelation('roles')->unsetRelation('permissions');

    expect(dynamicDashboard($receptionist)['widgets'])->toContain('follow_ups_due')->not->toContain('collections_today');
});

it('accepts dashboard choices only from the owner in Owner Mode, and only for staff widgets', function () {
    $owner = dynamicMember(owner: true);
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);

    actAsDynamic($receptionist)->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['follow_ups_due']])->assertForbidden();
    actAsDynamic($owner)->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['follow_ups_due']])->assertForbidden();

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass']);
    $this->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['owner_plan']])->assertSessionHasErrors('hidden.0');

    expect($this->clinic->fresh()->dashboard_config)->toBeNull();
});

it('reports a single branch, and the branch selector lives in the shell top bar, not on the Calendar', function () {
    actAsDynamic(dynamicMember(roles: [ClinicRole::Receptionist]))->followingRedirects()->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.accessible_branch_count', 1));

    expect(file_get_contents(resource_path('js/pages/ClinicPortal/Appointments/Index.tsx')))->not->toContain('BranchSwitcher')
        ->and(file_get_contents(resource_path('js/clinic/shell/TopBar.tsx')))->toContain('BranchSwitcher');
});

it('loads every widget the owner and an administrator see, without one failing', function () {
    dynamicPlan(['patients', 'appointments', 'billing']);
    $patient = Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Glance', 'last_name' => 'Patient', 'gender' => 'male', 'phone' => '9000000002', 'uhid' => 'UHID-DYN-2']);
    Appointment::create(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id, 'scheduled_at' => ClinicClock::now(), 'status' => 'cancelled']);

    // A practitioner lands on Today, not on the widget dashboard.
    foreach ([dynamicMember(owner: true), dynamicMember(roles: [ClinicRole::ClinicAdmin])] as $member) {
        $data = dynamicWidgetData($member);
        expect(collect($data)->filter(fn ($widget) => ($widget['error'] ?? false) === true)->keys()->all())->toBe([]);
    }

    // A cancelled booking is neither today's appointment nor one still to arrive.
    $data = dynamicWidgetData(dynamicMember(owner: true));
    expect($data['upcoming_appointments']['total'])->toBe(0);
});
