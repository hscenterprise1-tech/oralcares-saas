<?php

use App\LeadStatus;
use App\Models\CommercialDocument;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Convert — the transition the pipeline was missing a door for.
 *
 * The endpoint, its guards and the provisioning behind it have all existed and
 * been tested for a long time. Nothing anywhere called it, so a won deal never
 * became a customer, which is half of why the Accounts directory had nothing to
 * show. These tests cover the whole chain end to end, because each piece
 * passing on its own is what let the gap survive.
 */
beforeEach(function () {
    $this->provider = Organization::factory()->provider()->create(['name' => 'OralCares HQ']);

    $this->admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => $this->provider->id,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'company_name' => 'Harbour Dental Care',
        'contact_name' => 'Dr. Neha Iyer',
        'status' => LeadStatus::Negotiation,
    ]);
});

function acceptAgreement(Lead $lead): void
{
    CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'accepted',
    ]);
}

function settlePayment(Lead $lead): void
{
    $lead->payments()->create([
        'amount' => 49999,
        'status' => 'successful',
        'currency' => 'INR',
    ]);
}

it('refuses to convert without an accepted agreement', function () {
    settlePayment($this->lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.convert', $this->lead))
        ->assertSessionHas('error');

    expect($this->lead->fresh()->status)->not->toBe(LeadStatus::Won)
        ->and(Organization::customerAccounts()->count())->toBe(0);
});

it('refuses to convert without a settled payment', function () {
    acceptAgreement($this->lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.convert', $this->lead))
        ->assertSessionHas('error');

    expect(Organization::customerAccounts()->count())->toBe(0);
});

it('turns a won deal into a customer that Accounts can actually find', function () {
    acceptAgreement($this->lead);
    settlePayment($this->lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.convert', $this->lead))
        ->assertSessionHasNoErrors();

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Won)
        ->and($lead->customer_organization_id)->not->toBeNull();

    $customer = Organization::find($lead->customer_organization_id);

    /*
     * The three things that were each separately broken: the deal is won, a
     * customer organization exists, and the customer scope returns it. Under
     * the old scope this last one failed — a freshly provisioned customer had
     * no clinics and no won leads recorded against it yet, so it was invisible.
     */
    expect($customer->isCustomer())->toBeTrue()
        ->and($customer->name)->toBe('Harbour Dental Care')
        ->and(Organization::customerAccounts()->pluck('id'))->toContain($customer->id);
});

it('never counts the provider as one of its own customers', function () {
    acceptAgreement($this->lead);
    settlePayment($this->lead);

    $this->actingAs($this->admin)->post(route('company.crm.leads.convert', $this->lead));

    expect(Organization::customerAccounts()->pluck('id'))->not->toContain($this->provider->id);
});

it('gives the new customer the deal owner', function () {
    $rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->provider->id,
    ]);

    $this->lead->update(['assigned_user_id' => $rep->id]);
    acceptAgreement($this->lead);
    settlePayment($this->lead);

    $this->actingAs($this->admin)->post(route('company.crm.leads.convert', $this->lead));

    $customer = Organization::find($this->lead->fresh()->customer_organization_id);

    // Whoever closed it keeps it — a customer arriving unowned is the same
    // failure as a lead arriving unowned.
    expect($customer->assigned_user_id)->toBe($rep->id);
});
