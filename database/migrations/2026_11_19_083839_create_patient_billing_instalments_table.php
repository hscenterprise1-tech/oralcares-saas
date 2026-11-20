<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment plan on a bill: the balance split into dated instalments the
 * patient agreed to. Payments are allocated to the earliest unpaid
 * instalment (`BillInstalments`); the bill itself stays the one ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_billing_instalments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_billing_id');
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_on');
            $table->decimal('amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['patient_billing_id', 'clinic_id'], 'patient_billing_instalments_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('patient_billings')->cascadeOnDelete();
            $table->unique(['patient_billing_id', 'sequence'], 'patient_billing_instalments_sequence_unique');
            $table->index(['clinic_id', 'branch_id', 'due_on'], 'patient_billing_instalments_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_billing_instalments');
    }
};
