<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CustomerProvisioningEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Slice C — provisioning must record what the customer actually bought.
 *
 * Provisioning previously always wrote `company_plan_id => null` and derived
 * entitlements from quotation value thresholds, so Subscriptions and Renewals
 * had no way to report the purchased plan.
 */
function approvedQuotationForLead(Lead $lead, ?CompanyPlan $plan, float $total = 60000): CompanyQuotation
{
    $org = Organization::factory()->create();
    $preparedBy = CompanyEmployee::create([
        'organization_id' => $org->id,
        'user_id' => User::factory()->companyUser()->create(['organization_id' => $org->id])->id,
        'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
        'employment_status' => 'active',
    ]);

    return CompanyQuotation::create([
        'lead_id' => $lead->id,
        'company_plan_id' => $plan?->id,
        'prepared_by_id' => $preparedBy->id,
        'quotation_number' => 'QT-'.fake()->unique()->numberBetween(10000, 99999),
        'status' => QuotationStatus::Approved,
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'grand_total' => $total,
    ]);
}

it('links the subscription to the plan named on the approved quotation', function () {
    $lead = Lead::factory()->create(['company_name' => 'Plan Linked Clinic']);
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth',
        'max_users' => 12,
        'max_branches' => 4,
    ]);

    approvedQuotationForLead($lead, $plan);

    $org = app(CustomerProvisioningEngine::class)->provision($lead);

    $this->assertDatabaseHas('company_subscriptions', [
        'organization_id' => $org->id,
        'company_plan_id' => $plan->id,
    ]);
});

it('takes entitlements from the purchased plan rather than the value threshold', function () {
    $lead = Lead::factory()->create();
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth',
        'max_users' => 12,
        'max_branches' => 4,
    ]);

    // 150k would otherwise trigger the legacy 15-user/3-branch fallback tier.
    approvedQuotationForLead($lead, $plan, 150000);

    $org = app(CustomerProvisioningEngine::class)->provision($lead);

    $this->assertDatabaseHas('company_subscriptions', [
        'organization_id' => $org->id,
        'company_plan_id' => $plan->id,
        'max_users' => 12,
        'max_branches' => 4,
    ]);
});

it('names the license after the purchased plan', function () {
    $lead = Lead::factory()->create();
    $plan = CompanyPlan::factory()->create(['name' => 'Enterprise Pro', 'max_users' => 25, 'max_branches' => 8]);

    approvedQuotationForLead($lead, $plan);

    $org = app(CustomerProvisioningEngine::class)->provision($lead);

    $this->assertDatabaseHas('licenses', [
        'organization_id' => $org->id,
        'plan_name' => 'Enterprise Pro',
    ]);
});

it('falls back to value-based entitlements when the quotation names no plan', function () {
    $lead = Lead::factory()->create();

    approvedQuotationForLead($lead, null, 150000);

    $org = app(CustomerProvisioningEngine::class)->provision($lead);

    // Legacy behaviour preserved for quotations created before plan linkage.
    $this->assertDatabaseHas('company_subscriptions', [
        'organization_id' => $org->id,
        'company_plan_id' => null,
        'max_users' => 15,
        'max_branches' => 3,
    ]);
});

it('still provisions when there is no quotation at all', function () {
    $lead = Lead::factory()->create();

    $org = app(CustomerProvisioningEngine::class)->provision($lead);

    $this->assertDatabaseHas('company_subscriptions', [
        'organization_id' => $org->id,
        'max_users' => 5,
        'max_branches' => 1,
    ]);
});
