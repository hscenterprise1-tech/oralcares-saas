<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes the scale run (docs/architecture) showed were missing.
 *
 * - `appointments(clinic_id, scheduled_at)`: clinic-wide counts by day (the
 *   dashboard across all branches, the owner's seven-day trend) filter on
 *   the clinic and a time range with no branch. The calendar index leads
 *   with (clinic_id, branch_id), so without a branch it cannot range on time,
 *   and those counts read every appointment a hospital-sized clinic had ever
 *   booked (≈340 ms at 120k bookings).
 * - `clinic_operating_hours(day_of_week, branch_id, is_closed, close_time)`:
 *   lets `queue:close-day` ask the database which branches have closed for
 *   today across every tenant, instead of checking each open branch in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['clinic_id', 'scheduled_at'], 'appointments_clinic_schedule_index');
        });

        Schema::table('clinic_operating_hours', function (Blueprint $table) {
            $table->index(['day_of_week', 'branch_id', 'is_closed', 'close_time'], 'clinic_operating_hours_day_closing_index');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_operating_hours', function (Blueprint $table) {
            $table->dropIndex('clinic_operating_hours_day_closing_index');
        });

        // MySQL may have let this index stand in for the clinic_id foreign
        // key's own index; that one must exist again before this is dropped.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! Schema::hasIndex('appointments', 'appointments_clinic_id_foreign')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->index(['clinic_id'], 'appointments_clinic_id_foreign'));
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_clinic_schedule_index');
        });
    }
};
