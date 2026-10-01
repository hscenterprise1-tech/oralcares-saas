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

/** @return array{navigation: list<string>, actions: list<string>, widgets: list<string>} */
function dynamicSurface(AssertableInertia $page): array
{
    $props = $page->toArray()['props'];

    return [
        'navigation' => collect($props['clinic']['navigation'])->flatMap(fn (array $domain) => [$domain['key'], ...array_column($domain['children'], 'key')])->all(),
        'actions' => array_column($props['clinic']['actions'], 'key'),
        'widgets' => array_column($props['widgets'] ?? [], 'key'),
    ];
}

function dynamicDashboard(array $member, ?Branch $branch = null): array
{
    $surface = [];
    actAsDynamic($member, $branch)->get('/clinic/dashboard')->assertOk()
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
            $page->loadDeferredProps(function (AssertableInertia $reload) use (&$data) {
                $data = $reload->toArray()['props']['widgetData'];
            });
        });

    return $data;
}

/*
 * Navigation
 */

it('builds navigation from permissions: an admin gets Administration, a receptionist does not', function () {
    $admin = dynamicDashboard(dynamicMember(roles: [ClinicRole::ClinicAdmin]));
    $receptionist = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]));

    expect($admin['navigation'])->toContain('administration', 'staff', 'settings')
        ->and($receptionist['navigation'])->toBe(['dashboard', 'patients', 'appointments']);
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

it('leaves out a capability-gated domain the plan does not switch on', function () {
    registerFutureRoutes(['clinic.orders.index']);

    dynamicPlan(['patients', 'appointments']);
    expect(dynamicDashboard(dynamicMember(owner: true))['navigation'])->not->toContain('orders');
});

it('shows a capability-gated domain once the plan switches the capability on', function () {
    registerFutureRoutes(['clinic.orders.index']);

    dynamicPlan(['patients', 'appointments', 'lab']);
    expect(dynamicDashboard(dynamicMember(owner: true))['navigation'])->toContain('orders');
});

it('follows the clinic specialty: a physiotherapy clinic gets no New Case, a dental one does', function () {
    registerFutureRoutes(['clinic.physio.assessments.create']);
    $dental = dynamicDashboard(dynamicMember(owner: true))['actions'];

    $this->organization->update(['clinic_type' => ClinicType::Physiotherapy]);
    $physio = dynamicDashboard(dynamicMember(owner: true))['actions'];

    expect($dental)->toContain('new_case', 'repeat_case')->not->toContain('new_assessment')
        ->and($physio)->toContain('new_assessment')->not->toContain('new_case')->not->toContain('repeat_case');
});

it('resolves permissions at the active branch only', function () {
    $other = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id, 'status' => BranchStatus::Active]);
    $member = dynamicMember(roles: [ClinicRole::Receptionist], allBranches: false);
    $member['membership']->assignedBranches()->attach([$this->branch->id, $other->id]);

    expect(dynamicDashboard($member, $this->branch)['navigation'])->toContain('patients');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $member['user']->unsetRelation('roles')->unsetRelation('permissions');

    // The receptionist role was granted at the main branch only.
    expect(dynamicDashboard($member, $other)['navigation'])->toBe(['dashboard']);
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

it('sends one navigation list that the bar, the mobile menu and the palette all render', function () {
    actAsDynamic(dynamicMember(owner: true))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clinic.navigation')
            ->has('clinic.actions')
            ->missing('clinic.mobile_navigation')
            ->missing('clinic.command_palette'));

    // No portal component carries its own destination list or route names.
    $sources = collect(glob(resource_path('js/clinic/{shell,dashboard}/*.tsx'), GLOB_BRACE))
        ->push(resource_path('js/pages/ClinicPortal/Home.tsx'))
        ->mapWithKeys(fn (string $file) => [basename($file) => file_get_contents($file)]);

    foreach ($sources as $file => $source) {
        expect($source)->not->toMatch("/['\"]\\/clinic\\/(patients|appointments|reception|visits|admin)/")
            ->not->toMatch('/\broute\(\s*[\'"]clinic\./');
    }

    expect($sources['CommandPalette.tsx'])->toContain('destinations.flatMap')->toContain('actions');
});

it('offers the command palette exactly the actions the resolver allows', function () {
    $receptionist = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]));
    $nobody = dynamicDashboard(dynamicMember());

    expect($receptionist['actions'])->toBe(['new_case', 'repeat_case', 'new_appointment', 'request_leave'])
        ->and($nobody['actions'])->toBe([]);
});

/*
 * Dashboard
 */

it('resolves only the widgets a receptionist is eligible for', function () {
    // No lab or imaging in this plan, so no Investigations.
    expect(dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]))['widgets'])->toEqualCanonicalizing([
        'patients_today', 'appointments_today', 'waiting_now', 'collections_today',
        'todays_patients', 'todays_appointments',
        'quick_actions', 'needs_attention', 'cash_status',
    ]);
});

it('neither queries nor sends billing figures to someone without billing access', function () {
    $member = dynamicMember(roles: [ClinicRole::Doctor]);
    // The Doctor template includes billing.view; take it away at this branch.
    Role::findByName(ClinicRole::Doctor->value, 'web')->revokePermissionTo('billing.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = dynamicWidgetData($member);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($data)->toHaveKey('appointments_today')
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
    DB::table('patient_visits')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id, 'registration_type' => 'new', 'visit_date' => ClinicClock::today()->toDateString(), 'queue_status' => 'waiting', 'visit_status' => 'open', 'registration_fee_collected' => false, 'created_at' => now(), 'updated_at' => now()]);
    $owner = dynamicMember(owner: true);

    expect(dynamicWidgetData($owner)['todays_patients']['items'][0]['secondary'])->toStartWith('New case');

    $this->organization->update(['clinic_type' => ClinicType::Physiotherapy]);
    expect(dynamicWidgetData($owner)['todays_patients']['items'][0]['secondary'])->toStartWith('First visit');
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

    expect($data['appointments_today']['value'])->toBe(1)
        ->and($data['todays_appointments']['total'])->toBe(1)
        // Staff and branch overviews are clinic-wide.
        ->and($widgets)->not->toContain('staff_overview', 'branch_activity');
});

it('gives a multi-role user the union of their widgets, each once', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist, ClinicRole::Doctor], practitioner: true);

    $widgets = dynamicDashboard($member)['widgets'];

    expect($widgets)->toContain('todays_patients', 'collections_today', 'my_day', 'my_queue')
        ->and($widgets)->toBe(array_values(array_unique($widgets)));
});

it('adds owner widgets only while Owner Mode is on', function () {
    $owner = dynamicMember(owner: true);

    expect(dynamicDashboard($owner)['widgets'])->not->toContain('owner_performance', 'owner_plan', 'owner_security');

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $widgets = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$widgets) {
        $widgets = dynamicSurface($page)['widgets'];
    });

    expect($widgets)->toContain('owner_performance', 'owner_plan', 'owner_security', 'owner_revenue');
});

it('keeps the plan in force inside Owner Mode', function () {
    dynamicPlan(['patients', 'appointments']);
    $owner = dynamicMember(owner: true);

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $widgets = [];
    $this->get('/clinic/dashboard')->assertInertia(function (AssertableInertia $page) use (&$widgets) {
        $widgets = dynamicSurface($page)['widgets'];
    });

    expect($widgets)->toContain('owner_plan')->not->toContain('owner_revenue', 'collections_today');
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

    $engine = file_get_contents(resource_path('js/pages/ClinicPortal/Home.tsx'))
        .file_get_contents(resource_path('js/clinic/dashboard/DashboardGrid.tsx'));
    expect($engine)->not->toMatch('/Receptionist|Doctor|ClinicAdmin|isReception|role ===/');

    // Every widget names its requirements; none is unconditional except quick actions.
    foreach (DashboardWidgets::WIDGETS as $widget) {
        expect($widget['kind'] === 'actions' || $widget['requires'] !== [])->toBeTrue();
    }
});

it('puts no dashboard figures in the shared props or the first response', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist]);

    actAsDynamic($member)->get('/clinic/dashboard')
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            expect($props)->not->toHaveKey('widgetData')
                ->and(array_keys($props['clinic']))->not->toContain('metrics', 'widgets', 'widgetData', 'collections')
                ->and(json_encode($props['widgets']))->not->toMatch('/"(value|items|stats|total)"/');
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

it('gives a receptionist without billing access no billing widgets and runs no billing query', function () {
    $member = dynamicMember(roles: [ClinicRole::Receptionist]);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(['billing.view', 'billing.create', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $widgets = dynamicDashboard($member)['widgets'];
    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = dynamicWidgetData($member);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($widgets)->toContain('todays_patients', 'todays_appointments')->not->toContain('collections_today', 'cash_status')
        ->and(collect($data['needs_attention']['items'] ?? [])->pluck('id'))->not->toContain('pending_payments')
        ->and($queries)->not->toContain('patient_billing');
});

it('combines lab and imaging into one Investigations summary, only with the capability and permission', function () {
    $noCapability = dynamicDashboard(dynamicMember(roles: [ClinicRole::Receptionist]))['widgets'];

    dynamicPlan(['patients', 'appointments', 'billing', 'lab', 'xray']);
    DB::table('lab_orders')->insert([
        ['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Lab', 'last_name' => 'One', 'gender' => 'female', 'phone' => '9000000003', 'uhid' => 'UHID-DYN-3'])->id, 'lab_order_number' => 'L-1', 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);
    $billingStaff = dynamicMember(roles: [ClinicRole::BillingStaff]);

    $widgets = dynamicDashboard($receptionist)['widgets'];
    $stats = collect(dynamicWidgetData($receptionist)['investigations']['stats'])->pluck('value', 'key');

    expect($noCapability)->not->toContain('investigations')
        ->and($widgets)->toContain('investigations')->not->toContain('pending_lab_orders', 'pending_xrays')
        ->and($stats->keys()->all())->toBe(['ordered', 'pending', 'due', 'received'])
        ->and($stats['ordered'])->toBe(1)
        ->and(dynamicDashboard($billingStaff)['widgets'])->not->toContain('investigations');
});

it('lets the owner hide a widget from Reception, but never show one Reception may not see', function () {
    $owner = dynamicMember(owner: true);
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);
    $restricted = Role::findByName(ClinicRole::Receptionist->value, 'web');

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['cash_status']])->assertRedirect();

    expect(dynamicDashboard($receptionist)['widgets'])->toContain('collections_today')->not->toContain('cash_status');

    // Showing everything cannot give back what permissions take away. (The
    // owner's session moved to the receptionist above, which ended Owner Mode.)
    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass'])->assertRedirect();
    $this->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => []])->assertRedirect();
    $restricted->revokePermissionTo(['billing.view', 'billing.create', 'payment.collect']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $receptionist['user']->unsetRelation('roles')->unsetRelation('permissions');

    expect(dynamicDashboard($receptionist)['widgets'])->not->toContain('collections_today', 'cash_status');
});

it('accepts dashboard choices only from the owner in Owner Mode, and only for staff widgets', function () {
    $owner = dynamicMember(owner: true);
    $receptionist = dynamicMember(roles: [ClinicRole::Receptionist]);

    actAsDynamic($receptionist)->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['cash_status']])->assertForbidden();
    actAsDynamic($owner)->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['cash_status']])->assertForbidden();

    actAsDynamic($owner)->post('/clinic/owner-mode', ['password' => 'dynamic-pass']);
    $this->put('/clinic/dashboard/visibility', ['role' => 'Receptionist', 'hidden' => ['owner_plan']])->assertSessionHasErrors('hidden.0');

    expect($this->clinic->fresh()->dashboard_config)->toBeNull();
});

it('reports a single branch so the shell shows no branch selector', function () {
    actAsDynamic(dynamicMember(roles: [ClinicRole::Receptionist]))->get('/clinic/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clinic.accessible_branch_count', 1));

    expect(file_get_contents(resource_path('js/clinic/shell/ClinicPortalShell.tsx')))->toContain('accessible_branch_count > 1');
});
