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
        // 1. Patient Billings Table
        Schema::create('patient_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('clinic_employees')->nullOnDelete();

            $table->string('billing_number')->unique();
            $table->string('status')->default('pending'); // draft, pending, partially_paid, paid, cancelled, refunded
            $table->decimal('total_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('final_amount', 10, 2);
            $table->decimal('outstanding_balance', 10, 2);

            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Patient Billing Items Table
        Schema::create('patient_billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_billing_id')->constrained('patient_billings')->cascadeOnDelete();
            $table->foreignId('clinical_procedure_id')->constrained('clinical_procedures')->cascadeOnDelete();
            $table->foreignId('treatment_record_id')->nullable()->constrained('treatment_records')->nullOnDelete();

            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('final_amount', 10, 2);
            $table->timestamps();
        });

        // 3. Patient Billing Payments Table
        Schema::create('patient_billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_billing_id')->constrained('patient_billings')->cascadeOnDelete();
            $table->string('payment_method'); // cash, upi, card, bank_transfer, other
            $table->decimal('amount', 10, 2);
            $table->string('transaction_reference')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });

        // 4. Patient Billing Refunds Table
        Schema::create('patient_billing_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_billing_id')->constrained('patient_billings')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason')->nullable();

            $table->foreignId('refunded_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_billing_refunds');
        Schema::dropIfExists('patient_billing_payments');
        Schema::dropIfExists('patient_billing_items');
        Schema::dropIfExists('patient_billings');
    }
};
