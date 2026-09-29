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
        Schema::create('clinical_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('order_number')->unique();
            $table->string('order_type'); // laboratory, imaging, pharmacy, procedure, referral
            $table->string('status')->default('draft'); // draft, active, completed, cancelled
            $table->string('priority')->default('routine'); // routine, urgent, stat

            $table->text('clinical_instructions')->nullable();
            $table->json('details')->nullable();

            $table->timestamps();
        });

        Schema::create('clinical_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_order_id')->constrained('clinical_orders')->cascadeOnDelete();

            $table->string('item_code')->nullable();
            $table->string('item_name');
            $table->string('status')->default('pending'); // pending, in_progress, completed, cancelled
            $table->json('details')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_order_items');
        Schema::dropIfExists('clinical_orders');
    }
};
