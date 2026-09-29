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
        Schema::create('company_payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('payroll_code')->unique();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->string('month'); // e.g. "2026-08"
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('deductions', 10, 2)->default(0.00);
            $table->decimal('net_amount', 10, 2);
            $table->string('status')->default('draft'); // draft, processed, paid
            $table->date('paid_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_payrolls');
    }
};
