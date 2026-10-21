<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An adjustment to an issued bill's balance: a concession or a write-off,
 * with its reason and who made it. Like a refund it is its own record; the
 * bill's lines are never edited. `adjusted_amount` keeps the bill's total of
 * them, so outstanding = final − paid − adjusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_billing_id')->constrained('patient_billings')->restrictOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'created_at']);
        });

        Schema::table('patient_billings', function (Blueprint $table) {
            $table->decimal('adjusted_amount', 12, 2)->default(0)->after('refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('patient_billings', function (Blueprint $table) {
            $table->dropColumn('adjusted_amount');
        });
        Schema::dropIfExists('patient_billing_adjustments');
    }
};
