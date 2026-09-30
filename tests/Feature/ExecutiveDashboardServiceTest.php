<?php

use App\DemoStatus;
use App\LeadStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\ExecutiveDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * Every fixture in this file now acts as a master admin.
 *
 * Master Audit P0 — the Executive Dashboard used to query every model
 * unscoped, pooling every tenant's leads, quotations and payments together
 * for anyone who could reach the page. `ExecutiveDashboardService` now reads
 * through `CrmScope`, the same tenant/assignment boundary the rest of the
 * CRM uses — and `view executive dashboard` is in practice a senior/exec
 * permission, so a master admin (`CrmScope::LEVEL_ALL`, no constraint) is
 * the realistic acting user for this page and for these fixtures, which
 * create records with no tenant or assignment of their own.
 */
test('executive dashboard service returns valid payload structure without errors', function () {
    $service = app(ExecutiveDashboardService::class);
    $user = User::factory()->masterAdmin()->create();

    $data = $service->getDashboardPayload($user);

    expect($data)->toBeArray()
        ->and($data)->toHaveKeys(['commandCenter', 'snapshot', 'workspace', 'performance', 'operations', 'activityFeed']);

    expect($data['commandCenter'])->toHaveKeys(['userName', 'headlineStatus', 'businessHealth', 'todaysMission', 'expectedImpact', 'aiConfidence', 'todaysFocus', 'riskLevel']);
    expect($data['snapshot'])->toHaveKeys(['activeClinics', 'activeDemos', 'renewals', 'mrr']);
    expect($data['workspace'])->toHaveKeys(['attention', 'approvals', 'myWork']);
    expect($data['performance'])->toHaveKeys(['revenue', 'pipeline']);
    expect($data['operations'])->toHaveKeys(['cloudInfrastructure', 'databaseHealth', 'whatsappQueue', 'emailQueue', 'scheduledJobs', 'dailyBackup', 'supportSla', 'activeUsers']);
    expect($data['activityFeed'])->toBeArray();
});

test('executive dashboard service dynamically aggregates seeded leads and quotations', function () {
    $user = User::factory()->masterAdmin()->create();

    $lead = Lead::factory()->create([
        'company_name' => 'Apex Dental Care',
        'contact_name' => 'Dr. Vikram Seth',
        'status' => LeadStatus::New,
        'priority' => 'high',
    ]);

    // CompanyQuotation is the canonical quotation table the dashboard reads.
    $org = Organization::factory()->create();
    $preparedBy = CompanyEmployee::create([
        'organization_id' => $org->id,
        'user_id' => User::factory()->companyUser()->create(['organization_id' => $org->id])->id,
        'employee_code' => 'EMP-DASH1',
        'employment_status' => 'active',
    ]);

    CompanyQuotation::create([
        'lead_id' => $lead->id,
        'prepared_by_id' => $preparedBy->id,
        'quotation_number' => 'QT-10001',
        'status' => QuotationStatus::Draft,
        'subtotal' => 45000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'grand_total' => 45000,
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'amount' => 1420000,
        'status' => 'successful',
    ]);

    $service = app(ExecutiveDashboardService::class);
    $data = $service->getDashboardPayload($user);

    expect($data['workspace']['attention'])->not->toBeEmpty();
    expect($data['workspace']['approvals'])->not->toBeEmpty();
    expect($data['activityFeed'])->toBeArray();
});

test('active demos tile counts live demos from the canonical demos table', function () {
    $user = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->count(2)->create([
        'lead_id' => $lead->id,
        'status' => DemoStatus::Scheduled,
    ]);
    Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => DemoStatus::Confirmed,
    ]);

    $data = app(ExecutiveDashboardService::class)->getDashboardPayload($user);

    // Regression guard: this tile previously read the orphaned `company_demos`
    // table, which nothing writes to, so it always reported zero.
    expect($data['snapshot']['activeDemos']['value'])->toBe('3 Demos')
        ->and($data['snapshot']['activeDemos']['isPositive'])->toBeTrue();
});

test('active demos tile excludes completed and cancelled demos', function () {
    $user = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->create(['lead_id' => $lead->id, 'status' => DemoStatus::Scheduled]);
    Demo::factory()->create(['lead_id' => $lead->id, 'status' => DemoStatus::Completed]);
    Demo::factory()->create(['lead_id' => $lead->id, 'status' => DemoStatus::Cancelled]);
    Demo::factory()->create(['lead_id' => $lead->id, 'status' => DemoStatus::NoShow]);

    $data = app(ExecutiveDashboardService::class)->getDashboardPayload($user);

    expect($data['snapshot']['activeDemos']['value'])->toBe('1 Demos');
});

/**
 * Master Audit P0 — cross-tenant isolation regression suite.
 *
 * Every `ExecutiveDashboardService` query now reads through `CrmScope`. A
 * master admin still sees the whole platform (the deliberate top-level
 * bypass every other CRM surface also carries); a manager sees only their
 * own organization; an individual rep sees only what is assigned to them.
 */
it("keeps a manager's dashboard to their own organization, not another tenant's", function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $myOrg = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();

    $manager = User::factory()->companyUser()->create(['organization_id' => $myOrg->id]);
    $manager->assignRole('CRM Manager');

    $myLead = Lead::factory()->create(['organization_id' => $myOrg->id, 'priority' => 'high']);
    $otherLead = Lead::factory()->create(['organization_id' => $otherOrg->id, 'priority' => 'high']);

    $data = app(ExecutiveDashboardService::class)->getDashboardPayload($manager);

    $leadTitles = collect($data['workspace']['attention'])->pluck('title')->implode(' | ');

    expect($leadTitles)->toContain($myLead->company_name)
        ->and($leadTitles)->not->toContain($otherLead->company_name);
});

it("keeps an individual rep's dashboard to their own assigned leads only", function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);
    $rep->assignRole('Sales Representative');
    $otherRep = User::factory()->companyUser()->create(['organization_id' => $org->id]);

    $myLead = Lead::factory()->create(['organization_id' => $org->id, 'assigned_user_id' => $rep->id, 'priority' => 'high']);
    $theirLead = Lead::factory()->create(['organization_id' => $org->id, 'assigned_user_id' => $otherRep->id, 'priority' => 'high']);

    $data = app(ExecutiveDashboardService::class)->getDashboardPayload($rep);

    $leadTitles = collect($data['workspace']['attention'])->pluck('title')->implode(' | ');

    expect($leadTitles)->toContain($myLead->company_name)
        ->and($leadTitles)->not->toContain($theirLead->company_name);
});

it('still shows a master admin every tenant, unrestricted', function () {
    $admin = User::factory()->masterAdmin()->create();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $leadA = Lead::factory()->create(['organization_id' => $orgA->id, 'priority' => 'high']);
    $leadB = Lead::factory()->create(['organization_id' => $orgB->id, 'priority' => 'high']);

    $data = app(ExecutiveDashboardService::class)->getDashboardPayload($admin);

    $leadTitles = collect($data['workspace']['attention'])->pluck('title')->implode(' | ');

    expect($leadTitles)->toContain($leadA->company_name)
        ->and($leadTitles)->toContain($leadB->company_name);
});
