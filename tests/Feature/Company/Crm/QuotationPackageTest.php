<?php

use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);

    $this->lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
});

it('creates a quotation with a line item auto-seeded from the selected package', function () {
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth',
        'price' => 12999,
        'billing_period' => 'monthly',
    ]);

    post(route('company.crm.leads.create-quotation', $this->lead), [
        'lead_id' => $this->lead->id,
        'company_plan_id' => $plan->id,
    ])->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $this->lead->id)->with('items')->firstOrFail();

    expect($quotation->company_plan_id)->toBe($plan->id);
    expect($quotation->items)->toHaveCount(1);
    expect($quotation->items->first()->title)->toContain('Growth');
    // 18% GST on the package price: 12999 * 1.18 = 15338.82.
    expect((float) $quotation->grand_total)->toBe(15338.82);
});

it('still supports a freeform quotation with no package', function () {
    post(route('company.crm.leads.create-quotation', $this->lead), [
        'lead_id' => $this->lead->id,
        'amount' => 7500,
    ])->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $this->lead->id)->firstOrFail();

    expect($quotation->company_plan_id)->toBeNull();
    // 18% GST on the freeform amount: 7500 * 1.18 = 8850.00.
    expect((float) $quotation->grand_total)->toBe(8850.0);
});
