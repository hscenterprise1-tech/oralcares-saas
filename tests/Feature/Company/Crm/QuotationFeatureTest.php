<?php

use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\CompanyEmployee;
use App\Models\Organization;

beforeEach(function () {
    $this->org = Organization::factory()->create();

    // QuotationPolicy authorizes on CRM permissions from Slice C onward, so a
    // company user with no grants is correctly denied. This fixture represents
    // a sales employee, so it carries the rep-level permission set.
    $this->companyUser = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]));

    $this->clinicUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->org->id,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
    ]);
});

it('allows company user to create quotation and logs activity', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        'amount' => 5000,
        'status' => 'draft',
        'valid_until' => now()->addDays(30)->format('Y-m-d'),
        'notes' => 'Custom software package',
    ];

    $response = $this->actingAs($this->companyUser)
        ->post(route('company.crm.quotations.store'), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('company_quotations', [
        'lead_id' => $this->lead->id,
        'status' => 'draft',
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'lead',
        'subject_id' => $this->lead->id,
        'type' => 'quotation',
    ]);
});

it('prevents clinic user from creating quotation', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        'amount' => 5000,
        'status' => 'draft',
    ];

    $response = $this->actingAs($this->clinicUser)
        ->post(route('company.crm.quotations.store'), $payload);

    $response->assertForbidden();
});

it('allows company user to update quotation', function () {
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $this->companyUser->id,
    ]);

    $quotation = CompanyQuotation::create([
        'organization_id' => $this->org->id,
        'prepared_by_id' => $employee->id,
        'quotation_number' => 'QT-1001',
        'version' => 1,
        'lead_id' => $this->lead->id,
        'subtotal' => 5000,
        'grand_total' => 5000,
        'status' => 'draft',
    ]);

    /*
     * Phase 2 — quotation state-machine guards. `update()` no longer accepts
     * a `status` field at all: every real transition (send/approve/reject)
     * has its own guarded endpoint now, and a generic "update" accepting an
     * arbitrary status string was exactly the alternate unguarded path the
     * guard exists to close. Status stays `draft`; only `notes` changes.
     */
    $payload = [
        'status' => 'sent',
        'notes' => 'Emailed to client',
    ];

    $response = $this->actingAs($this->companyUser)
        ->put(route('company.crm.quotations.update', $quotation), $payload);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('company_quotations', [
        'id' => $quotation->id,
        'status' => 'draft',
        'notes' => 'Emailed to client',
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'lead',
        'subject_id' => $this->lead->id,
        'type' => 'quotation',
    ]);
});
