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

/**
 * The Command Center's sections, for whoever runs the clinic (the owner or an administrator).
 *
 * @return array<string, mixed>
 */
function dynamicSections(array $member, ?Branch $branch = null): array
{
    $sections = [];
    actAsDynamic($member, $branch)->get('/clinic/dashboard')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$sections) {
            $sections = $page->component('ClinicPortal/CommandCenter/Index', false)->toArray()['props']['sections'];
        });

    return $sections;
}

/*
 * Navigation
 */

it('builds navigation from permissions: an admin gets the clinic section, a receptionist does not', function () {
    $admin = dynamicDashboard(dynamicMember(roles: [ClinicRole::ClinicAdmin]));
    $receptionist = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]));

    expect($admin['navigation'])->toContain('staff', 'settings')
        // Billing too: the desk runs billing in full since 2026-11-05.
        ->and($receptionist['navigation'])->toBe(['dashboard', 'appointments', 'patients', 'billing', 'visits', 'follow_ups', 'notifications', 'support', 'leave']);
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

    // The receptionist role was granted at the main branch only: no front office (and so no dashboard) here.
    expect(dynamicDashboard($member, $other)['navigation'])->toBe(['notifications', 'support']);
});

it('shows owner-only navigation to a doctor-owner only while Owner Mode is on, and to any other owner always', function () {
    $owner = dynamicMember(owner: true, practitioner: true);
    $ownerDomains = [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'dashboard', 'order' => 1, 'route' => 'clinic.notifications.index'],
        ['key' => 'owner_console', 'label' => 'Owner console', 'icon' => 'owner', 'order' => 2, 'route' => 'clinic.dashboard', 'requires' => ['owner_mode' => true]],
    ];
    Route::middleware(['web', 'clinic_portal', 'clinic_context'])->get('/clinic/__nav-probe', fn () => response()->json(
        array_column(ClinicNavigation::for(app(ClinicAccess::class), [], $ownerDomains), 'key'),
    ));

    actAsDynamic($owner)->get('/clinic/__nav-probe')->assertExactJson(['home']);

    $this->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->get('/clinic/__nav-probe')->assertExactJson(['home', 'owner_console']);

    // An owner who does not treat patients: always, with no mode.
    actAsDynamic(dynamicMember(owner: true))->get('/clinic/__nav-probe')->assertExactJson(['home', 'owner_console']);
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

    // A receptionist takes payments at the desk and runs billing in full (2026-11-05).
    expect($receptionist['actions'])->toBe(['new_case', 'repeat_case', 'new_appointment', 'collect_payment', 'request_leave'])
        ->and($nobody['actions'])->toBe([]);
});

/*
 * Dashboard
 */

it('gives a receptionist the Front Desk as their one dashboard', function () {
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);

    actAsDynamic($receptionist)->get('/clinic/dashboard')->assertRedirect(route('clinic.reception'));
    actAsDynamic($receptionist)->get('/clinic/reception')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ClinicPortal/FrontDesk/Index', true));
    $navigation = collect(dynamicDashboard($receptionist)['navigation']);
    expect($navigation->all())->toContain('dashboard')->not->toContain('front_desk');
});

it('resolves only the blocks a clinic administrator is eligible for, in order', function () {
    // Not the owner, so no money, people, security or activity; one branch, so no branch table;
    // not a practitioner, so no "Your patients".
    $sections = dynamicSections(dynamicMember(roles: [ClinicRole::ClinicAdmin]));

    expect(array_keys($sections))->toBe(['today', 'spending', 'decisions'])
        ->and(array_column($sections['today']['figures'], 'key'))->toBe(['patients_today', 'appointments_today', 'waiting_now', 'with_doctor_now']);
});

it('neither queries nor sends billing figures to someone without billing access', function () {
    // An administrator (not the owner) gets no money on the Command Center, and no billing query runs for it.
    // (A receptionist without billing access: see the Front Desk's own test below.)
    DB::flushQueryLog();
    DB::enableQueryLog();
    $sections = dynamicSections(dynamicMember(roles: [ClinicRole::ClinicAdmin]));
    $adminQueries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($sections)->not->toHaveKey('money')
        ->and($adminQueries)->not->toContain('patient_billing');
});

it('leaves billing widgets out when the plan has no billing', function () {
    dynamicPlan(['patients', 'appointments']);

    expect(dynamicDashboard(dynamicMember(owner: true))['widgets'])
        ->not->toContain('collections_today', 'cash_status');
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

    $sections = dynamicSections($member);
    $figures = collect($sections['today']['figures'])->keyBy('key');

    expect($figures['appointments_today']['value'])->toBe(1)
        ->and($figures['patients_today']['value'])->toBe(0)
        // The branch table is clinic-wide.
        ->and($sections)->not->toHaveKey('branches');
});

it('gives a multi-role user the union of their widgets, each once', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist, ClinicRole::Doctor], practitioner: true);

    // Every job, every workspace; the Front Desk is the dashboard; each destination once.
    actAsDynamic($member)->get('/clinic/dashboard')->assertRedirect(route('clinic.reception'));
    $workspaces = [];
    actAsDynamic($member)->get('/clinic/reception')->assertInertia(function (AssertableInertia $page) use (&$workspaces) {
        $workspaces = array_column($page->toArray()['props']['clinic']['workspaces'], 'key');
    });
    $navigation = dynamicDashboard($member)['navigation'];

    // The desk runs billing in full (2026-11-05), so the Billing work center is the union's too.
    expect($workspaces)->toBe(['front_desk', 'today', 'billing'])
        ->and($navigation)->toContain('dashboard', 'follow_ups', 'billing')->not->toContain('front_desk', 'new_case', 'repeat_case', 'consultations')
        ->and($navigation)->toBe(array_values(array_unique($navigation)));
});

it('gives an owner who does not treat patients the owner blocks from sign-in, and a doctor-owner only in Owner Mode', function () {
    $plain = dynamicSections(dynamicMember(owner: true));
    expect($plain['money']['state'])->toBe('ready')->and($plain['security']['state'])->toBe('ready');

    // A doctor-owner: Doctor Mode first, so the doctor's home (the Doctor Dashboard) and no owner blocks.
    $owner = dynamicMember(owner: true, practitioner: true);
    actAsDynamic($owner)->get('/clinic/dashboard')->assertRedirect(route('clinic.consultations.index'));

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $sections = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$sections) {
        $sections = $page->toArray()['props']['sections'];
    });

    expect($sections['money']['state'])->toBe('ready')->and($sections['security']['state'])->toBe('ready');
});

it('keeps the plan in force inside Owner Mode', function () {
    dynamicPlan(['patients', 'appointments']);
    $owner = dynamicMember(owner: true);

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $sections = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$sections) {
        $sections = $page->toArray()['props']['sections'];
    });

    // No billing on the plan: no money block at all, while security still shows.
    expect($sections['security']['state'])->toBe('ready')->and($sections)->not->toHaveKey('money');
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

            expect($props)->toHaveKey('sections')
                ->and(array_keys($props['clinic']))->not->toContain('metrics', 'widgets', 'widgetData', 'collections', 'sections');
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
    $inbox = fn (array $member) => collect(dynamicSections($member)['decisions']['items'] ?? [])->keyBy('id');
    dynamicPlan(['patients', 'appointments', 'billing']);
    $noCapability = $inbox(dynamicMember(roles: [ClinicRole::ClinicAdmin]));

    CompanySubscription::query()->delete();
    dynamicPlan(['patients', 'appointments', 'billing', 'lab', 'xray']);
    $admin = dynamicMember(roles: [ClinicRole::ClinicAdmin]);

    expect($noCapability->keys()->all())->not->toContain('reports_due')
        ->and($inbox($admin)['reports_due']['count'])->toBe(1)
        // A summary, not a reporting block of its own.
        ->and(array_keys(dynamicSections($admin)))->not->toContain('investigations');
});

it('has no staff-dashboard settings: there is no staff dashboard to configure', function () {
    expect(Route::has('clinic.dashboard.visibility'))->toBeFalse();
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

    // Every Command Center block loads for the owner (in Owner Mode and out) and an administrator.
    $owner = dynamicMember(owner: true);
    foreach ([$owner, dynamicMember(roles: [ClinicRole::ClinicAdmin])] as $member) {
        expect(collect(dynamicSections($member))->filter(fn ($section) => $section['state'] === 'error')->keys()->all())->toBe([]);
    }
    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $sections = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$sections) {
        $sections = $page->toArray()['props']['sections'];
    });
    expect(collect($sections)->filter(fn ($section) => $section['state'] !== 'ready')->keys()->all())->toBe([]);

    // A cancelled booking is not today's appointment.
    expect(collect($sections['today']['figures'])->firstWhere('key', 'appointments_today')['value'])->toBe(0);
});
