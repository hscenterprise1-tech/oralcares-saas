<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HQ attendance, leave and the working calendar.
 *
 * Entirely new, entirely Company-side. The existing `leaves` and `shifts`
 * tables are not touched and are not reused: `leaves.employee_id` carries a
 * foreign key to `clinic_employees`, so a `company_employees` row cannot be
 * stored there even if the two domains were the same thing — and they are not.
 * `shifts` schedules a clinic *resource* (a chair, a room), not a person's
 * working day.
 *
 * Two rules shape what follows.
 *
 * Nothing here encodes a policy nobody has decided. There is no default working
 * week, no seeded leave type, no accrual rate, no carry-forward cap and no
 * standard working day in hours. Every one of those is configuration, and an
 * unconfigured system says so rather than assuming a Monday-to-Friday office.
 *
 * And every change to a balance is a recorded entry rather than a recomputed
 * figure, which is what makes accrual and carry-forward auditable later without
 * inventing the rule that produces them today.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ───────────────────────────── working calendar ─────────────────── */

        /*
         * The weekly pattern, one row per weekday.
         *
         * Deliberately seeded with nothing. Which days a company works is a
         * decision, and defaulting to Mon–Fri would quietly make it for them.
         * An empty table means "not configured", and every surface says so.
         */
        Schema::create('company_working_days', function (Blueprint $table) {
            $table->id();
            /* 0 = Sunday … 6 = Saturday, matching Carbon's dayOfWeek. */
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('day_of_week');
        });

        /*
         * Company holidays. Past years are kept, which is the history.
         */
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date');
            $table->string('name');
            $table->string('description')->nullable();
            /* Whether the company treats it as optional rather than closed. */
            $table->boolean('is_optional')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('holiday_date');
            $table->index('holiday_date', 'company_holidays_date_index');
        });

        /* ─────────────────────────────── leave ──────────────────────────── */

        /*
         * Leave types are configuration, not code.
         *
         * The clinic migration's comment named "casual, sick, vacation,
         * emergency, custom" — a comment, never an enum, never validated. None
         * of those is seeded here: what leave a company offers is theirs to
         * define.
         *
         * `is_paid` is recorded but consumed by nothing. It is the seam payroll
         * will read one day; today it changes no figure anywhere.
         */
        Schema::create('company_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('allows_half_day')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('colour', 7)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('company_leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            /*
             * Whole days requested, as a decimal. A half day counts 0.5 — that
             * is arithmetic, not policy. Part-days are counted separately in
             * hours below, because converting hours into a day fraction needs a
             * standard working day that nothing in this system establishes.
             */
            $table->decimal('total_days', 6, 2)->default(0);
            $table->decimal('total_part_day_hours', 6, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            /* How the decider was entitled to decide: manager, or HR fallback. */
            $table->string('decided_via')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        /*
         * One row per calendar day of the request.
         *
         * This is what makes half days and part days real rather than a label
         * on the request: a week off can be four full days and one half, and
         * the balance and any future payroll input both read these rows.
         */
        Schema::create('company_leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('company_leave_requests')->cascadeOnDelete();
            $table->date('leave_date');
            /* full | half | part */
            $table->string('portion')->default('full');
            /* first_half | second_half — only meaningful for a half day. */
            $table->string('half_period')->nullable();
            /* Hours, for a part day. Never converted into a day fraction. */
            $table->decimal('hours', 5, 2)->nullable();
            $table->decimal('day_value', 4, 2)->default(1);
            $table->timestamps();

            $table->unique(['leave_request_id', 'leave_date']);
            $table->index('leave_date');
        });

        /*
         * A balance per employee, per leave type, per year.
         *
         * The columns are totals, and every one of them is the sum of recorded
         * entries below. Nothing accrues on its own: no rate, no frequency and
         * no cap is encoded, because none has been decided.
         */
        Schema::create('company_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('company_leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('opening_balance', 7, 2)->default(0);
            $table->decimal('accrued', 7, 2)->default(0);
            $table->decimal('carried_forward', 7, 2)->default(0);
            $table->decimal('adjusted', 7, 2)->default(0);
            $table->decimal('used', 7, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'period_year'], 'company_leave_balance_unique');
        });

        /*
         * The ledger behind every balance.
         *
         * Accrual and carry-forward are recorded events here, which is the
         * whole foundation: when a policy is eventually decided, it writes
         * these rows, and the balances it produced before that stay exactly as
         * they were.
         */
        Schema::create('company_leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')->constrained('company_leave_balances')->cascadeOnDelete();
            /* opening | accrual | carry_forward | adjustment | consumption | reversal */
            $table->string('entry_type');
            /* Positive adds to the balance, negative takes from it. */
            $table->decimal('days', 7, 2);
            $table->date('effective_date');
            $table->string('reason')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained('company_leave_requests')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['leave_balance_id', 'entry_type']);
        });

        /* ───────────────────────────── attendance ───────────────────────── */

        Schema::create('company_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            /*
             * The employee's local calendar day, not the server's.
             *
             * A check-in at 23:40 in Kolkata and one at 19:10 in London are the
             * same instant on different dates, so the date this belongs to is
             * resolved in the employee's own timezone and stored here.
             */
            $table->date('work_date');
            /* present | absent | partial | on_leave | holiday | weekly_off */
            $table->string('status')->default('present');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            /*
             * The timezone in force when the record was written, snapshotted.
             * Moving somebody between offices later must not silently re-date
             * everything they have ever logged.
             */
            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('worked_minutes')->nullable();
            /* employee | manager | hr | system */
            $table->string('source')->default('employee');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* One record per person per day, enforced rather than hoped for. */
            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        /*
         * Every correction, kept.
         *
         * A manager or HR fixing a forgotten check-out is normal and expected;
         * doing it invisibly is not. One row per field changed, with who and
         * why.
         */
        Schema::create('company_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained('company_attendance_records')->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('attendance_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_attendance_corrections');
        Schema::dropIfExists('company_attendance_records');
        Schema::dropIfExists('company_leave_balance_entries');
        Schema::dropIfExists('company_leave_balances');
        Schema::dropIfExists('company_leave_request_days');
        Schema::dropIfExists('company_leave_requests');
        Schema::dropIfExists('company_leave_types');
        Schema::dropIfExists('company_holidays');
        Schema::dropIfExists('company_working_days');
    }
};
