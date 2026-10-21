<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinic's internal pay records: each employee's salary structure (a new
 * row per change, so history is kept), advances, and payroll periods with one
 * entry per employee and the lines that make it up. Statutory deductions
 * (PF, ESI, TDS, professional tax) are not computed: a clinic records them as
 * deduction lines. Everything is clinic-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->restrictOnDelete();
            $table->date('effective_from');
            // monthly | daily | hourly | per_session
            $table->string('salary_type', 12);
            $table->decimal('base_amount', 12, 2)->default(0);
            // Share of payments collected on the practitioner's bills.
            $table->decimal('commission_percent', 5, 2)->nullable();
            // Paid per completed treatment session (physiotherapy).
            $table->decimal('per_session_rate', 10, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_employee_id', 'effective_from'], 'salary_structure_once_per_day');
            $table->index(['clinic_id', 'clinic_employee_id', 'effective_from'], 'salary_structure_lookup');
        });

        Schema::create('clinic_salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('recovered_amount', 12, 2)->default(0);
            $table->date('given_on');
            $table->string('payment_method', 20)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'clinic_employee_id', 'status'], 'salary_advance_open');
        });

        Schema::create('clinic_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            // draft (editable) → finalised (locked) → paid
            $table->string('status', 12)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'period_start']);
        });

        Schema::create('clinic_payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('clinic_payroll_periods')->cascadeOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->restrictOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->constrained('clinic_salary_structures')->nullOnDelete();
            $table->string('salary_type', 12);
            $table->decimal('units', 8, 2)->nullable();
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->string('status', 12)->default('draft');
            $table->string('payslip_number', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'clinic_employee_id'], 'payroll_entry_once');
            $table->index(['clinic_id', 'clinic_employee_id'], 'payroll_entry_employee');
        });

        Schema::create('clinic_payroll_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained('clinic_payroll_entries')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            // base | session_pay | commission | incentive | allowance | deduction | unpaid_leave | advance_recovery
            $table->string('kind', 20);
            $table->string('description', 160);
            // Positive adds to pay, negative takes away.
            $table->decimal('amount', 12, 2);
            $table->boolean('automatic')->default(false);
            $table->foreignId('salary_advance_id')->nullable()->constrained('clinic_salary_advances')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_payroll_entry_lines');
        Schema::dropIfExists('clinic_payroll_entries');
        Schema::dropIfExists('clinic_payroll_periods');
        Schema::dropIfExists('clinic_salary_advances');
        Schema::dropIfExists('clinic_salary_structures');
    }
};
