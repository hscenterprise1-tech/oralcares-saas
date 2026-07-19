<?php

use App\Events\LowStockDetected;
use App\Events\PurchaseOrderCreated;
use App\Events\StockConsumed;
use App\Events\StockReceived;
use App\InventoryTransactionType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\InventoryBatch;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Services\InventoryCatalogService;
use App\Services\InventoryReservationService;
use App\Services\InventoryTransactionService;
use App\Services\PurchaseOrderService;
use App\Services\StockService;
use App\Services\VendorService;
use App\TreatmentStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('can register catalog items and vendors', function () {
    $clinic = Clinic::factory()->create();

    $item = app(InventoryCatalogService::class)->createItem([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
        'minimum_stock' => 10.00,
        'reorder_level' => 15.00,
    ]);

    $vendor = app(VendorService::class)->createVendor([
        'clinic_id' => $clinic->id,
        'name' => 'Dental Supply Corp',
        'email' => 'sales@dentalsupply.com',
    ]);

    expect($item->sku)->toBe('COMP-001')
        ->and($vendor->name)->toBe('Dental Supply Corp');
});

test('available stock calculation and transaction flow with low stock triggers', function () {
    Event::fake([LowStockDetected::class, StockReceived::class, StockConsumed::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $item = app(InventoryCatalogService::class)->createItem([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
        'minimum_stock' => 10.00,
        'reorder_level' => 15.00,
    ]);

    $batch = InventoryBatch::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'BATCH-001',
        'current_quantity' => 0.00,
    ]);

    $txService = app(InventoryTransactionService::class);
    $stockService = app(StockService::class);

    // 1. Add Opening Stock (Stock Received)
    $txService->recordTransaction($branch, $item, $batch, InventoryTransactionType::OpeningStock, 15.00, 'Init stock', null, $doctor);

    expect($stockService->getAvailableStock($branch, $item))->toBe(15.00);
    Event::assertDispatched(StockReceived::class);

    // 2. Consume Stock below minimum (Trigger Low Stock)
    $txService->recordTransaction($branch, $item, $batch, InventoryTransactionType::Consumption, 8.00, 'Clinic use', null, $doctor);

    expect($stockService->getAvailableStock($branch, $item))->toBe(7.00);
    Event::assertDispatched(StockConsumed::class);
    Event::assertDispatched(LowStockDetected::class);
});

test('stock reservation and consumption workflow', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $txRecord = TreatmentRecord::create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'code' => 'D2740',
        'description' => 'Crown',
        'status' => TreatmentStatus::Planned,
        'price' => 500.00,
    ]);

    $item = app(InventoryCatalogService::class)->createItem([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
    ]);

    $batch = InventoryBatch::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'BATCH-001',
        'current_quantity' => 10.00,
    ]);

    $reservationService = app(InventoryReservationService::class);
    $stockService = app(StockService::class);

    // 1. Reserve Stock
    $reservation = $reservationService->reserveStock($batch, 3.00, $txRecord);
    expect($stockService->getAvailableStock($branch, $item))->toBe(7.00)
        ->and($batch->fresh()->reserved_quantity)->toBe('3.00');

    // 2. Consume Reservation
    $reservationService->consumeReservation($reservation, $doctor);
    expect($batch->fresh()->current_quantity)->toBe('7.00')
        ->and($batch->fresh()->reserved_quantity)->toBe('0.00');
});

test('branch stock transfer logistics', function () {
    $clinic = Clinic::factory()->create();
    $source = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Source Branch']);
    $dest = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Destination Branch']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $item = app(InventoryCatalogService::class)->createItem([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
    ]);

    $srcBatch = InventoryBatch::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $source->id,
        'inventory_item_id' => $item->id,
        'batch_number' => 'BATCH-001',
        'current_quantity' => 20.00,
    ]);

    $stockService = app(StockService::class);

    // Transfer 5 units
    $stockService->transferStock($clinic, $source, $dest, $item, $srcBatch, 5.00, $doctor);

    expect($stockService->getAvailableStock($source, $item))->toBe(15.00)
        ->and($stockService->getAvailableStock($dest, $item))->toBe(5.00);
});

test('purchase orders workflow with status receipt promotions', function () {
    Event::fake([PurchaseOrderCreated::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $item = app(InventoryCatalogService::class)->createItem([
        'clinic_id' => $clinic->id,
        'sku' => 'COMP-001',
        'name' => 'Composite Resin A2',
        'category' => 'dental_materials',
        'unit' => 'piece',
    ]);

    $vendor = app(VendorService::class)->createVendor([
        'clinic_id' => $clinic->id,
        'name' => 'Apex Vendor',
    ]);

    $poService = app(PurchaseOrderService::class);

    // 1. Create PO
    $po = $poService->createPurchaseOrder($clinic, $branch, $vendor, [
        [
            'inventory_item_id' => $item->id,
            'quantity_ordered' => 50.00,
            'unit_cost' => 12.50,
        ],
    ], $doctor);

    expect($po->po_number)->toBe("PO-{$clinic->id}-00001")
        ->and($po->items)->toHaveCount(1);
    Event::assertDispatched(PurchaseOrderCreated::class);

    // 2. Receive Stock
    $poService->receiveStock($po, [
        [
            'inventory_item_id' => $item->id,
            'quantity_received' => 50.00,
            'batch_number' => 'BATCH-ABC',
            'expiry_date' => '2027-12-31',
        ],
    ], $doctor);

    expect($po->fresh()->status->value)->toBe('received');
});
