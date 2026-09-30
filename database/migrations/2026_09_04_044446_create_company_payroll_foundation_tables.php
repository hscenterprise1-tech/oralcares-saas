<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll that can be audited.
 *
 * What existed was a single `company_payrolls` table where a person typed a
 * gross and a deductions figure and the row was written `paid` in the same
 * breath — no salary structure, no period, no calculation, no approval, no
 * lock, and one unexplained deductions scalar. Its eighteen rows are real and
 * are left exactly as they are; nothing here touches that table.
 *
 * Deliberately absent: any statutory rule. No PF, TDS, ESI or PT rate is
 * encoded anywhere below. Components are configured by the business, and the
 * seeder's flat 12% was never a business rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a payroll line can be. Configured, not hard-coded — the point of
         * the table is that "deductions: 30000" stops being unexplained.
         */
        Schema::create('company_compensation_components', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            /* earning | deduction */
            $table->string('type');
            /* fixed | percentage_of_base — how `value` is read */
            $table->string('calculation_type')->default('fixed');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        /*
         * What somebody earns, and from when.
         *
         * Effective-dated so a raise does not rewrite what was paid last month.
         * `effective_to` is null for the arrangement in force; superseding one
         * closes the previous row rather than editing it.
         */
        Schema::create('company_employee_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->decimal('base_salary', 12, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_to']);
        });

        /* The components attached to one compensation arrangement. */
        Schema::create('company_compensation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_id')->constrained('company_employee_compensations')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('company_compensation_components')->cascadeOnDelete();
            /* Read as an amount or a percentage of base, per the component. */
            $table->decimal('value', 12, 2);
            $table->timestamps();

            $table->unique(['compensation_id', 'component_id']);
        });

        /*
         * A payroll run.
         *
         * Draft → Calculated → Pending approval → Approved → Locked → Paid.
         * Each transition records who and when, because "who approved this"
         * is the question an audit actually asks.
         */
        Schema::create('company_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');

            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->unsignedInteger('entry_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->text('payment_notes')->nullable();

            $table->timestamps();

            /* One run per calendar window. */
            $table->unique(['period_start', 'period_end']);
            $table->index('status');
        });

        /*
         * One employee's pay for one run — a snapshot, not a live view.
         *
         * The figures are copied at calculation time so a later change to
         * somebody's salary cannot rewrite what a closed run says it paid.
         */
        Schema::create('company_payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('company_payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            /* Which arrangement was read. Kept for provenance, never re-read. */
            $table->foreignId('compensation_id')->nullable()->constrained('company_employee_compensations')->nullOnDelete();

            $table->string('employee_code');
            $table->string('employee_name');
            $table->decimal('base_salary', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('deduction_amount', 12, 2);
            $table->decimal('net_amount', 12, 2);
            $table->timestamps();

            /* Nobody is paid twice in one run. */
            $table->unique(['period_id', 'employee_id']);
        });

        /*
         * The lines behind one entry, snapshotted by value.
         *
         * The component's code, name and type are copied rather than referenced,
         * so renaming or retiring a component leaves historical payslips intact.
         */
        Schema::create('company_payroll_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('company_payroll_entries')->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('company_compensation_components')->nullOnDelete();
            $table->string('component_code');
            $table->string('component_name');
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['entry_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payroll_entry_lines');
        Schema::dropIfExists('company_payroll_entries');
        Schema::dropIfExists('company_payroll_periods');
        Schema::dropIfExists('company_compensation_lines');
        Schema::dropIfExists('company_employee_compensations');
        Schema::dropIfExists('company_compensation_components');
    }
};
