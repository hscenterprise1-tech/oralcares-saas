<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one practitioner schedule: when a clinician works at a branch, as
 * weekly windows. It sits beside `clinic_operating_hours` (when the branch
 * is open) and uses the same day numbering: 0 = Sunday … 6 = Saturday.
 *
 * It is keyed by `clinic_employee_id`, the id every appointment, visit and
 * queue item already uses for "the doctor", and always names a branch.
 *
 * Rows are copied from `doctor_availabilities` (keyed by practitioner
 * profile, 1 = Monday … 7 = Sunday) only where the profile's staff record,
 * the branch and the clinic all agree. Anything else stays where it is and
 * is reported by `clinic:staff-audit`. The old table is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practitioner_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_available')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['clinic_employee_id', 'branch_id', 'day_of_week', 'start_time'], 'practitioner_schedules_window_unique');
            $table->index(['clinic_id', 'branch_id', 'day_of_week'], 'practitioner_schedules_branch_day_index');
        });

        $rows = DB::table('doctor_availabilities')
            ->join('doctor_profiles', 'doctor_profiles.id', '=', 'doctor_availabilities.doctor_profile_id')
            ->join('clinic_employees', 'clinic_employees.id', '=', 'doctor_profiles.clinic_employee_id')
            ->join('branches', 'branches.id', '=', 'doctor_availabilities.branch_id')
            ->whereColumn('branches.clinic_id', 'clinic_employees.clinic_id')
            ->whereColumn('doctor_profiles.clinic_id', 'clinic_employees.clinic_id')
            ->get([
                'clinic_employees.clinic_id',
                'doctor_availabilities.branch_id',
                'clinic_employees.id as clinic_employee_id',
                'doctor_availabilities.day_of_week',
                'doctor_availabilities.start_time',
                'doctor_availabilities.end_time',
                'doctor_availabilities.is_available',
            ]);

        foreach ($rows as $row) {
            DB::table('practitioner_schedules')->insertOrIgnore([
                'clinic_id' => $row->clinic_id,
                'branch_id' => $row->branch_id,
                'clinic_employee_id' => $row->clinic_employee_id,
                'day_of_week' => ((int) $row->day_of_week) % 7,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'is_available' => (bool) $row->is_available,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_schedules');
    }
};
