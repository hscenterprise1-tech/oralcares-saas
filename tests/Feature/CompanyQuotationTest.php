<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\CompanyQuotationItem;
use App\Models\Lead;
use App\QuotationStatus;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('quotation can be created with items and calculates correct totals', function () {
    $employee = CompanyEmployee::factory()->create();
    $lead = Lead::factory()->create();

    $data = [
        'lead_id' => $lead->id,
        'tax' => 150.00,
        'notes' => 'Test notes',
    ];

    $items = [
        [
            'title' => 'Dental HMS License',
            'quantity' => 2,
            'unit_price' => 1000.00,
            'discount' => 100.00,
        ],
        [
            'title' => 'WhatsApp Setup',
            'quantity' => 1,
            'unit_price' => 500.00,
            'discount' => 50.00,
        ],
    ];

    $service = app(QuotationService::class);
    $quotation = $service->createQuotation($data, $items, $employee);

    expect($quotation)->not->toBeNull()
        ->and($quotation->quotation_number)->toStartWith('QT-')
        ->and($quotation->version)->toBe(1)
        ->and($quotation->status)->toBe(QuotationStatus::Draft);

    expect((float) $quotation->subtotal)->toBe(2500.00)
        ->and((float) $quotation->discount)->toBe(150.00)
        ->and((float) $quotation->grand_total)->toBe(2500.00);

    expect($quotation->items)->toHaveCount(2);
    $firstItem = $quotation->items->where('title', 'Dental HMS License')->first();
    expect((float) $firstItem->total)->toBe(1900.00);

    expect($quotation->activities)->toHaveCount(1)
        ->and($quotation->activities->first()->event)->toBe('created');
});

test('draft quotation can be updated with new items and recalculates totals', function () {
    $employee = CompanyEmployee::factory()->create();
    $quotation = CompanyQuotation::factory()->create(['prepared_by_id' => $employee->id, 'status' => QuotationStatus::Draft]);

    $items = [
        [
            'title' => 'Cloud Backup',
            'quantity' => 1,
            'unit_price' => 300.00,
            'discount' => 0.00,
        ],
    ];

    $service = app(QuotationService::class);
    $service->updateQuotation($quotation, ['notes' => 'Updated notes'], $items, $employee);

    $quotation->refresh();
    expect($quotation->notes)->toBe('Updated notes')
        ->and((float) $quotation->subtotal)->toBe(300.00)
        ->and((float) $quotation->grand_total)->toBe(300.00);

    expect($quotation->activities->pluck('event'))->toContain('updated');
});

test('non-draft quotation cannot be updated directly', function () {
    $employee = CompanyEmployee::factory()->create();
    $quotation = CompanyQuotation::factory()->create([
        'prepared_by_id' => $employee->id,
        'status' => QuotationStatus::Sent,
    ]);

    $service = app(QuotationService::class);

    $this->expectException(LogicException::class);
    $service->updateQuotation($quotation, [], [], $employee);
});

test('quotation versioning preserves history and increments versions', function () {
    $employee = CompanyEmployee::factory()->create();
    $quotation = CompanyQuotation::factory()->create([
        'prepared_by_id' => $employee->id,
        'status' => QuotationStatus::Sent,
    ]);
    CompanyQuotationItem::factory()->create(['company_quotation_id' => $quotation->id]);

    $service = app(QuotationService::class);
    $revised = $service->createRevision($quotation, $employee);

    expect($revised->id)->not->toBe($quotation->id)
        ->and($revised->quotation_number)->toBe($quotation->quotation_number)
        ->and($revised->version)->toBe(2)
        ->and($revised->parent_id)->toBe($quotation->id)
        ->and($revised->status)->toBe(QuotationStatus::Draft);

    expect($revised->items)->toHaveCount(1)
        ->and($revised->items->first()->title)->toBe($quotation->items->first()->title);

    $revised3 = $service->createRevision($revised, $employee);
    expect($revised3->version)->toBe(3)
        ->and($revised3->parent_id)->toBe($quotation->id);
});

test('status transitions log audit trail activities', function () {
    $employee = CompanyEmployee::factory()->create();
    $quotation = CompanyQuotation::factory()->create(['prepared_by_id' => $employee->id]);

    $service = app(QuotationService::class);
    $service->transitionStatus($quotation, QuotationStatus::Sent, $employee, 'Proposal sent to customer');

    expect($quotation->status)->toBe(QuotationStatus::Sent);

    $activity = $quotation->activities()->where('event', 'sent')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Proposal sent to customer');
});
