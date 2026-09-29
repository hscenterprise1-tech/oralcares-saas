<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CrmMetricsService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'HR Manager', 'guard_name' => 'web']);

    // CRM policies authorize on permissions from Phase 0 onward. CRM Manager
    // additionally carries the management permissions CrmScope reads to widen
    // data scope; Accountant and HR deliberately carry none, which is what the
    // "non-CRM employee" assertions in this file rely on.
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));
});

/** Expected revenue annualises MRR when no explicit ARR is recorded. */
function annualised(float $mrr): float
{
    return $mrr * 12;
}

test('1. CRM Admin sees organization-wide metrics on default load', function () {
    $org = Organization::factory()->create(['name' => 'OralCares India']);
    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $user->assignRole('CRM Manager');

    $response = $this->actingAs($user)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Company/Crm/Dashboard')
        ->has('kpis')
        ->where('scope.is_manager', true)
        ->where('scope.can_view_team_performance', true)
        // Pipeline is deferred so the personal queue can paint first.
        ->loadDeferredProps(['pipeline'], fn (Assert $reload) => $reload->has('pipeline'))
    );
});

test('2. CRM Admin sees every rep lead in organization totals', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $admin->assignRole('CRM Manager');

    $rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $rep->assignRole('Sales Representative');

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $admin->id,
        'status' => LeadStatus::New,
        'expected_mrr' => 30000,
        'expected_arr' => 0,
    ]);

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::New,
        'expected_mrr' => 70000,
        'expected_arr' => 0,
    ]);

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.active_pipeline_value', fn ($val) => (float) $val === annualised(100000))
    );
});

test('3. Sales Employee sees only their own assigned pipeline', function () {
    // Behaviour change, deliberate: this previously asserted that an employee
    // received organization-wide totals. Holding a CRM permission is authority
    // to reach the surface, not authority over every record on it — scoping is
    // now resolved by CrmScope from management permissions.
    $org = Organization::factory()->create();
    $employee = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $employee->assignRole('Sales Representative');

    $otherRep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $otherRep->assignRole('Sales Representative');

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $employee->id,
        'status' => LeadStatus::New,
        'expected_mrr' => 20000,
        'expected_arr' => 0,
    ]);

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $otherRep->id,
        'status' => LeadStatus::New,
        'expected_mrr' => 80000,
        'expected_arr' => 0,
    ]);

    $response = $this->actingAs($employee)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.active_pipeline_value', fn ($val) => (float) $val === annualised(20000))
        ->where('scope.is_manager', false)
        ->where('scope.can_view_team_performance', false)
        // `team_performance` and `winback` left the Overview payload entirely
        // in the V2 rebuild — comparing reps is analysis and belongs in
        // Reports, and win-backs are a cut of the Deals Lost view. What matters
        // here is that the employee's *pipeline* stays their own, asserted
        // above; the scope flags below are what the page reads to decide.
        ->missing('team_performance')
        ->missing('winback')
    );
});

/*
 * Rep performance is no longer on the Overview, but the rule it encodes — who
 * counts as a rep — still governs the Sales Teams page and will govern Reports.
 * The test therefore moved down to the service rather than being deleted with
 * the widget.
 */
test('4. Team performance covers reps holding leads and excludes non-CRM roles', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $admin->assignRole('CRM Manager');

    $salesRep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
        'name' => 'John Sales Rep',
    ]);
    $salesRep->assignRole('Sales Representative');
    Lead::factory()->create(['organization_id' => $org->id, 'assigned_user_id' => $salesRep->id]);

    $accountant = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
        'name' => 'Alice Accountant',
    ]);
    $accountant->assignRole('Accountant');

    $hr = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
        'name' => 'Bob HR',
    ]);
    $hr->assignRole('HR Manager');

    $this->actingAs($admin);

    $repIds = array_column(app(CrmMetricsService::class)->repPerformance($admin), 'user_id');

    expect($repIds)->toContain($salesRep->id)
        ->and($repIds)->not->toContain($accountant->id)
        ->and($repIds)->not->toContain($hr->id);
});

test('5. Master Admin organization context resolution', function () {
    $admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
    ]);

    expect($admin->resolveOrganizationId())->toBeGreaterThan(0);

    $response = $this->actingAs($admin)->get('/company/crm/overview');
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page->where('scope.level', 'all'));
});

test('6. Won and lost counts exclude the active pipeline', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $admin->assignRole('CRM Manager');

    Lead::factory()->count(3)->create(['organization_id' => $org->id, 'status' => LeadStatus::Won]);
    Lead::factory()->create(['organization_id' => $org->id, 'status' => LeadStatus::Lost]);
    Lead::factory()->create(['organization_id' => $org->id, 'status' => LeadStatus::New]);

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.won_this_period', 3)
        ->where('kpis.lost_this_period', 1)
        ->where('kpis.active_pipeline_count', 1)
    );
});

test('7. New Leads date filtering', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $org->id,
    ]);
    $admin->assignRole('CRM Manager');

    Lead::factory()->create(['organization_id' => $org->id, 'created_at' => now()]);
    Lead::factory()->create(['organization_id' => $org->id, 'created_at' => now()->subMonths(2)]);

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page->where('kpis.new_leads', 1));
});

test('8. Tenant isolation across organizations', function () {
    $org1 = Organization::factory()->create(['name' => 'Org 1']);
    $org2 = Organization::factory()->create(['name' => 'Org 2']);

    $admin1 = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org1->id]);
    $admin1->assignRole('CRM Manager');

    Lead::factory()->create(['organization_id' => $org1->id, 'status' => LeadStatus::New, 'expected_mrr' => 15000, 'expected_arr' => 0]);
    Lead::factory()->create(['organization_id' => $org2->id, 'status' => LeadStatus::New, 'expected_mrr' => 95000, 'expected_arr' => 0]);

    $response = $this->actingAs($admin1)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.active_pipeline_value', fn ($val) => (float) $val === annualised(15000))
    );
});

test('9. No post-Won operational data leakage', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $admin->assignRole('CRM Manager');

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->missing('onboarding')
        ->missing('subscriptions')
        ->missing('renewals')
    );
});

test('10. Empty state reports zero pipeline and unavailable velocity', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $admin->assignRole('CRM Manager');

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.active_pipeline_value', fn ($val) => (float) $val === 0.0)
        ->where('kpis.new_leads', 0)
        ->where('kpis.weighted_pipeline_value', fn ($val) => (float) $val === 0.0)
        // Absent history must read as unavailable, never as a cycle time of zero.
        ->loadDeferredProps(['pipeline'], fn (Assert $reload) => $reload
            ->where('velocity.average_cycle_time_days', null)
            ->where('velocity.cycle_time_sample_size', 0)
        )
    );
});

test('11. Reporting period defaults to the current month', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $admin->assignRole('CRM Manager');

    $response = $this->actingAs($admin)->get('/company/crm/overview');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('period.current', 'month')
        ->has('period.options', 3)
        ->where('kpis.period_start', now()->startOfMonth()->toDateString())
    );
});

test('12. Reporting period widens the KPI window when a period is selected', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $admin->assignRole('CRM Manager');

    $this->actingAs($admin)->get('/company/crm/overview?period=quarter')
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.current', 'quarter')
            ->where('kpis.period_start', now()->startOfQuarter()->toDateString())
        );

    /*
     * `year` was retired with the Overview rebuild. With the movement feed as
     * the only period-scoped section, a year and a month return the same rows
     * for any pipeline of this size, so the option was noise. Week replaces it.
     */
    $this->actingAs($admin)->get('/company/crm/overview?period=week')
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.current', 'week')
            ->where('kpis.period_start', now()->startOfWeek()->toDateString())
        );
});

test('13. An unrecognised reporting period falls back to the month rather than an unbounded range', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $admin->assignRole('CRM Manager');

    $response = $this->actingAs($admin)->get('/company/crm/overview?period=all-time');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('period.current', 'month')
        ->where('kpis.period_start', now()->startOfMonth()->toDateString())
    );
});

test('14. A period selection respects lead scope and does not leak other reps', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $rep->assignRole('Sales Representative');
    $other = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
    $other->assignRole('Sales Representative');

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $other->id,
        'status' => LeadStatus::Negotiation,
        'expected_mrr' => 50000,
    ]);

    $response = $this->actingAs($rep)->get('/company/crm/overview?period=quarter');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('period.current', 'quarter')
        ->where('kpis.active_pipeline_value', fn ($val) => (float) $val === 0.0)
    );
});
