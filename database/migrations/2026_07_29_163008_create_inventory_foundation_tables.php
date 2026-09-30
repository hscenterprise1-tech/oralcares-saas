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
        if (! Schema::hasTable('inventory_items')) {
            Schema::create('inventory_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

                $table->string('item_type'); // drug, consumable, retail
                $table->string('sku')->nullable();
                $table->string('name');
                $table->string('unit_of_measure'); // e.g. tablet, vial, box
                $table->boolean('is_billable')->default(false);
                $table->decimal('default_price', 12, 2)->default(0);
                $table->json('details')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_locations')) {
            Schema::create('stock_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

                $table->string('name');
                $table->string('type')->default('store'); // pharmacy, store, clinic_room

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_location_id')->constrained('stock_locations')->cascadeOnDelete();
                $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

                $table->string('lot_number')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('quantity_on_hand', 12, 2)->default(0);

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_transactions')) {
            Schema::create('stock_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_location_id')->constrained('stock_locations')->cascadeOnDelete();
                $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
                $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();

                $table->string('transaction_type'); // receive, dispense, consume, adjust, transfer
                $table->decimal('quantity', 12, 2); // can be negative for deductions

                $table->nullableMorphs('referenceable'); // polymorphic link to ClinicalProcedure, Prescription, etc
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

                $table->text('notes')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('inventory_items');
    }
};
