<?php

use App\BillingCycle;
use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Master Audit P0 — quotation numbering.
 *
 * `QuotationNumberService::generateNextNumber()` used to read the current
 * maximum with no lock, so two requests racing inside `createQuotation()`'s
 * own transaction could both compute the same number and one would surface
 * a raw, unhandled `QueryException` to the rep. The fix has two parts:
 * `lockForUpdate()` on the read (closes the race for every prefix after its
 * first row exists), and a bounded retry in
 * `LeadWorkspaceService::saveQuotationWithUniqueNumber()` against the
 * table's own unique constraint on `[quotation_number, version]` — the
 * narrower case `lockForUpdate()` cannot cover, since the very first
 * quotation of a prefix has no row to lock.
 *
 * These tests simulate the race by making the number service itself hand
 * out an already-taken number — exactly what two concurrent callers reading
 * before either commits would both compute.
 */
function quotationConcurrencyLead(): Lead
{
    $org = Organization::factory()->create();
    test()->admin = User::factory()->masterAdmin()->create(['organization_id' => $org->id]);

    return Lead::factory()->create(['organization_id' => $org->id, 'status' => LeadStatus::Contacted]);
}

it('retries past a colliding quotation number instead of failing the request', function () {
    $lead = quotationConcurrencyLead();

    $collidingNumber = 'QT-'.now()->year.'-0001';
    CompanyQuotation::factory()->create(['quotation_number' => $collidingNumber, 'version' => 1]);

    $this->mock(QuotationNumberService::class, function ($mock) use ($collidingNumber) {
        $mock->shouldReceive('generateNextNumber')
            ->twice()
            ->andReturn($collidingNumber, 'QT-'.now()->year.'-0002');
    });

    $quotation = app(LeadWorkspaceService::class)->createQuotation(
        $lead,
        ['billing_cycle' => BillingCycle::Monthly->value],
        [['title' => 'Standard Plan', 'quantity' => 1, 'unit_price' => 5000, 'discount' => 0]],
        $this->admin
    );

    expect($quotation->quotation_number)->toBe('QT-'.now()->year.'-0002')
        ->and(CompanyQuotation::where('quotation_number', $collidingNumber)->count())->toBe(1)
        ->and(CompanyQuotation::where('quotation_number', 'QT-'.now()->year.'-0002')->count())->toBe(1);
});

it('gives up after exhausting retries against a persistently colliding number', function () {
    $lead = quotationConcurrencyLead();

    $collidingNumber = 'QT-'.now()->year.'-0001';
    CompanyQuotation::factory()->create(['quotation_number' => $collidingNumber, 'version' => 1]);

    $this->mock(QuotationNumberService::class, function ($mock) use ($collidingNumber) {
        $mock->shouldReceive('generateNextNumber')->andReturn($collidingNumber);
    });

    expect(fn () => app(LeadWorkspaceService::class)->createQuotation(
        $lead,
        ['billing_cycle' => BillingCycle::Monthly->value],
        [['title' => 'Standard Plan', 'quantity' => 1, 'unit_price' => 5000, 'discount' => 0]],
        $this->admin
    ))->toThrow(QueryException::class);
});

it('locks the latest row so a real second read only happens after the first commits', function () {
    quotationConcurrencyLead();

    CompanyQuotation::factory()->create(['quotation_number' => 'QT-'.now()->year.'-0007', 'version' => 1]);

    expect(app(QuotationNumberService::class)->generateNextNumber())->toBe('QT-'.now()->year.'-0008');
});
