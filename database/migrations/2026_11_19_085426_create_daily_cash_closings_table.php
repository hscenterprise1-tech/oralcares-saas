<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The desk's close of a business day at a branch: what the ledger says came
 * in by each method, the cash actually counted, and the difference with the
 * reason. One per branch per day; a snapshot, never a second ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('business_date');
            $table->json('methods');
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['branch_id', 'business_date'], 'daily_cash_closings_branch_day_unique');
            $table->index(['clinic_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_cash_closings');
    }
};
