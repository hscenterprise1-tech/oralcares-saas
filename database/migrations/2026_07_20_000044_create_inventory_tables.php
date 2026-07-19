<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Master Inventory Item Catalog
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('item_code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // dental_materials, medicines, instruments, consumables, etc.
            $table->string('unit'); // piece, box, pack, ml
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->decimal('minimum_stock', 10, 2)->default(0.00);
            $table->decimal('reorder_level', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Inventory Vendors
        Schema::create('inventory_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('gst_number')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Inventory Batches
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->string('batch_number');
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('purchase_cost', 10, 2)->default(0.00);
            $table->string('supplier_batch_reference')->nullable();

            $table->decimal('opening_quantity', 10, 2)->default(0.00);
            $table->decimal('current_quantity', 10, 2)->default(0.00);
            $table->decimal('reserved_quantity', 10, 2)->default(0.00);
            $table->decimal('damaged_quantity', 10, 2)->default(0.00);
            $table->decimal('expired_quantity', 10, 2)->default(0.00);

            $table->timestamps();

            $table->unique(['branch_id', 'inventory_item_id', 'batch_number'], 'inv_batch_uniq');
        });

        // 4. Inventory Transactions (Immutable History)
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();

            $table->string('transaction_type'); // opening_stock, purchase, adjustment, consumption, etc.
            $table->decimal('quantity', 10, 2);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });

        // 5. Stock Reservations
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);

            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('status')->default('active'); // active, consumed, cancelled

            $table->timestamps();
        });

        // 6. Purchase Orders
        Schema::create('inventory_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('inventory_vendors')->cascadeOnDelete();

            $table->string('po_number')->unique();
            $table->string('status')->default('draft'); // draft, requested, approved, etc.
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });

        // 7. Purchase Order Items
        Schema::create('inventory_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_purchase_order_id')
                ->constrained('inventory_purchase_orders')
                ->cascadeOnDelete()
                ->name('po_item_po_fk');

            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->cascadeOnDelete()
                ->name('po_item_item_fk');

            $table->decimal('quantity_ordered', 10, 2);
            $table->decimal('quantity_received', 10, 2)->default(0.00);
            $table->decimal('unit_cost', 10, 2);
            $table->timestamps();
        });

        // 8. Branch Stock Transfers
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();

            $table->foreignId('source_branch_id')
                ->constrained('branches')
                ->cascadeOnDelete()
                ->name('tx_src_branch_fk');

            $table->foreignId('destination_branch_id')
                ->constrained('branches')
                ->cascadeOnDelete()
                ->name('tx_dest_branch_fk');

            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->cascadeOnDelete()
                ->name('tx_item_fk');

            $table->foreignId('inventory_batch_id')
                ->constrained('inventory_batches')
                ->cascadeOnDelete()
                ->name('tx_batch_fk');

            $table->decimal('quantity', 10, 2);
            $table->string('status')->default('in_transit'); // in_transit, received, cancelled
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_purchase_order_items');
        Schema::dropIfExists('inventory_purchase_orders');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_vendors');
        Schema::dropIfExists('inventory_items');
    }
};
