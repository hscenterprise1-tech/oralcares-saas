<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the appointment engine records beyond the original booking: the
     * service booked, its recurring series, where it came from, when it was
     * confirmed, cancelled or moved, and, for a new case booked before
     * registration, the caller's name and mobile (the patient is linked when
     * they arrive and register). Plus the indexes the calendar's range
     * queries and the conflict checks read by.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->change();
            $table->string('prospect_name', 150)->nullable()->after('patient_id');
            $table->string('prospect_phone', 20)->nullable()->after('prospect_name');
            $table->string('source', 20)->default('reception')->after('appointment_type');
            $table->foreignId('service_id')->nullable()->after('room_id')->constrained('clinical_procedures')->nullOnDelete();
            $table->foreignId('appointment_series_id')->nullable()->after('service_id')->constrained('appointment_series')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('rescheduled_at')->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);

            $table->index(['clinic_id', 'branch_id', 'scheduled_at'], 'appointments_calendar_index');
            $table->index(['practitioner_id', 'scheduled_at'], 'appointments_practitioner_time_index');
            $table->index(['room_id', 'scheduled_at'], 'appointments_room_time_index');
            $table->index(['clinic_id', 'prospect_phone'], 'appointments_prospect_phone_index');
        });
    }

    public function down(): void
    {
        // MySQL may let the composite indexes serve these foreign keys and
        // drop their own; give back any that are gone before the composites go.
        foreach (['clinic_id', 'practitioner_id', 'room_id'] as $column) {
            if (! Schema::hasIndex('appointments', "appointments_{$column}_foreign")) {
                Schema::table('appointments', fn (Blueprint $table) => $table->index($column, "appointments_{$column}_foreign"));
            }
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_calendar_index');
            $table->dropIndex('appointments_practitioner_time_index');
            $table->dropIndex('appointments_room_time_index');
            $table->dropIndex('appointments_prospect_phone_index');
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('appointment_series_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['prospect_name', 'prospect_phone', 'source', 'confirmed_at', 'cancelled_at', 'cancellation_reason', 'rescheduled_at', 'reschedule_count']);
        });
    }
};
