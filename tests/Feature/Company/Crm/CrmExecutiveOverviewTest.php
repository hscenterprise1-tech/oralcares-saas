<?php

use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Trial;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CrmMetricsService;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The two aggregates the executive Overview needed and the CRM did not have.
 *
 * Both follow the rule the rest of CrmMetricsService already keeps: a figure
 * the data cannot answer is null, never zero. "No trial has reached a decision"
 * and "no trial has ever converted" are opposite statements to a founder.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->manager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->manager->assignRole('CRM Manager');

    $this->metrics = app(CrmMetricsService::class);
});

function executiveOverviewLead(Organization $org): Lead
{
    return Lead::factory()->create(['organization_id' => $org->id]);
}

// ------------------------------------------------------------- trial health

it('separates a running trial from one whose end date has passed', function () {
    Trial::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'status' => TrialStatus::Active,
        'end_date' => now()->addDays(5),
    ]);

    Trial::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'status' => TrialStatus::Active,
        'end_date' => now()->subDays(9),
    ]);

    $health = $this->metrics->trialHealth($this->manager);

    expect($health['running'])->toBe(1)
        ->and($health['undecided'])->toBe(1)
        ->and($health['ending_soon'])->toBe(1);
});

it('reports trial conversion as null until something has been decided', function () {
    Trial::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'status' => TrialStatus::Active,
        'end_date' => now()->addDays(3),
    ]);

    $health = $this->metrics->trialHealth($this->manager);

    expect($health['conversion_rate'])->toBeNull()
        ->and($health['decided_sample_size'])->toBe(0);
});

it('computes trial conversion only from trials that reached a decision', function () {
    /*
     * `converted` is now `quotation_requested` — it was set when a customer
     * asked for a price, which is not a sale. What this test measures is
     * unchanged: a trial that reached a decision either way.
     */
    foreach ([TrialStatus::QuotationRequested, TrialStatus::QuotationRequested, TrialStatus::Expired] as $status) {
        Trial::factory()->create([
            'lead_id' => executiveOverviewLead($this->org)->id,
            'status' => $status,
            'end_date' => now()->subDay(),
        ]);
    }

    // Still running, so it must not dilute the rate.
    Trial::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'status' => TrialStatus::Active,
        'end_date' => now()->addDays(4),
    ]);

    $health = $this->metrics->trialHealth($this->manager);

    expect($health['decided_sample_size'])->toBe(3)
        ->and($health['conversion_rate'])->toBe(66.7)
        ->and($health['converted_this_period'])->toBe(2)
        ->and($health['lapsed_this_period'])->toBe(1);
});

// -------------------------------------------------------- commercial health

it('values only the quotations that are still open', function () {
    CompanyQuotation::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'organization_id' => $this->org->id,
        'status' => QuotationStatus::Draft,
        'grand_total' => 100000,
    ]);

    CompanyQuotation::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'organization_id' => $this->org->id,
        'status' => QuotationStatus::Sent,
        'grand_total' => 50000,
    ]);

    // Closed, so it belongs to neither the count nor the value.
    CompanyQuotation::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'organization_id' => $this->org->id,
        'status' => QuotationStatus::Rejected,
        'grand_total' => 900000,
    ]);

    $health = $this->metrics->commercialHealth($this->manager);

    expect($health['open_count'])->toBe(2)
        ->and($health['open_value'])->toBe(150000.0)
        ->and($health['drafts'])->toBe(1);
});

it('carries the approval threshold so the page never hardcodes it', function () {
    config()->set('crm.quotation_approval_discount_percent', 5);

    $health = $this->metrics->commercialHealth($this->manager);

    expect($health['approval_threshold_percent'])->toBe(5.0);
});

/*
 * Settlement is reported separately from what has been quoted.
 *
 * A quotation on the table is not money; money is a settled quotation, and the
 * invoice is raised from that settlement. Summing the two would describe a
 * pipeline that does not exist, so this pins the separation.
 *
 * This replaces an assertion on `agreements_accepted`. The agreement step no
 * longer exists — the flow is Quotation → Approval → Payment → Invoice → Won —
 * and a figure counting a document type nothing creates could only ever report
 * history on a live dashboard.
 */
it('reports settlement apart from what is merely quoted', function () {
    CompanyQuotation::factory()->create([
        'lead_id' => executiveOverviewLead($this->org)->id,
        'organization_id' => $this->org->id,
        'status' => QuotationStatus::Sent,
        'grand_total' => 250000,
    ]);

    $health = $this->metrics->commercialHealth($this->manager);

    expect($health['open_count'])->toBe(1)
        ->and($health['open_value'])->toBe(250000.0)
        /* Sent, not approved and not paid — so nothing has settled. */
        ->and($health['settled_count_this_period'])->toBe(0)
        ->and($health['settled_value_this_period'])->toBe(0.0)
        ->and($health['payments_received_this_period'])->toBe(0);
});

it('counts a quotation as settled once its invoice is raised', function () {
    $lead = executiveOverviewLead($this->org);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $this->org->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 250000,
        'tax' => 0,
        'discount' => 0,
        'grand_total' => 250000,
    ]);

    /* Settling it in full raises the invoice, which is what this figure counts. */
    Payment::factory()->create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 250000,
        'status' => 'successful',
    ]);

    $health = $this->metrics->commercialHealth($this->manager);

    expect($health['settled_count_this_period'])->toBe(1)
        ->and($health['settled_value_this_period'])->toBe(250000.0)
        ->and($health['payments_received_this_period'])->toBe(1);
});

// ------------------------------------------------------------- the payload

/*
 * The Overview's payload shape had no test at all — the existing suites
 * exercise CrmMetricsService directly and never hit the route. Restructuring
 * it into immediate and deferred halves is exactly the change that would have
 * gone unnoticed.
 */
it('renders the executive overview with the immediate half of its payload', function () {
    $response = $this->actingAs($this->manager)->get(route('company.crm.overview'));

    $response->assertSuccessful()->assertInertia(
        fn ($page) => $page
            ->component('Company/Crm/Dashboard')
            ->has('kpis')
            ->has('attention')
            ->has('personal')
            ->has('scope')
            ->has('period')
    );
});

it('defers the expensive aggregates rather than blocking the first paint', function () {
    $response = $this->actingAs($this->manager)->get(route('company.crm.overview'));

    $deferred = collect($response->viewData('page')['deferredProps'] ?? [])->flatten()->all();

    expect($deferred)->toContain('pipeline')
        ->and($deferred)->toContain('forecast')
        ->and($deferred)->toContain('trials')
        ->and($deferred)->toContain('commercials');
});
