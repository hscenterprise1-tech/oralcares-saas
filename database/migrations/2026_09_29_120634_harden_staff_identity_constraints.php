<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database guarantees for the staff identity chain
 * User → ClinicMembership → ClinicEmployee → practitioner profile.
 *
 * - A login is at most one staff record per clinic: unique (clinic_id, user_id).
 *   Unlinked staff (user_id NULL) are unaffected.
 * - A staff record has at most one practitioner profile, and that profile
 *   belongs to the staff record's own clinic (clinic_id NOT NULL).
 *
 * Nothing is guessed or deleted. A profile missing its clinic takes the
 * clinic of its own staff record; any duplicate or mismatch stops the
 * migration with the offending ids so it can be resolved by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateLinks = DB::table('clinic_employees')
            ->whereNotNull('user_id')
            ->select('clinic_id', 'user_id', DB::raw('COUNT(*) as staff'))
            ->groupBy('clinic_id', 'user_id')
            ->having('staff', '>', 1)
            ->get();

        $duplicateProfiles = DB::table('doctor_profiles')
            ->select('clinic_employee_id', DB::raw('COUNT(*) as profiles'))
            ->groupBy('clinic_employee_id')
            ->having('profiles', '>', 1)
            ->get();

        DB::table('doctor_profiles')
            ->whereNull('clinic_id')
            ->orderBy('id')
            ->each(function (object $profile) {
                $clinicId = DB::table('clinic_employees')->where('id', $profile->clinic_employee_id)->value('clinic_id');

                if ($clinicId) {
                    DB::table('doctor_profiles')->where('id', $profile->id)->update(['clinic_id' => $clinicId]);
                }
            });

        $wrongClinic = DB::table('doctor_profiles')
            ->leftJoin('clinic_employees', 'clinic_employees.id', '=', 'doctor_profiles.clinic_employee_id')
            ->where(fn ($query) => $query->whereNull('doctor_profiles.clinic_id')
                ->orWhereNull('clinic_employees.id')
                ->orWhereColumn('doctor_profiles.clinic_id', '!=', 'clinic_employees.clinic_id'))
            ->pluck('doctor_profiles.id');

        if ($duplicateLinks->isNotEmpty() || $duplicateProfiles->isNotEmpty() || $wrongClinic->isNotEmpty()) {
            throw new RuntimeException(
                'Staff identity data needs manual resolution before this migration can run. '
                .'Logins on more than one staff record: '.$duplicateLinks->toJson()
                .' Staff with more than one practitioner profile: '.$duplicateProfiles->toJson()
                .' Practitioner profiles with no or a different clinic than their staff record: '.$wrongClinic->toJson()
            );
        }

        Schema::table('clinic_employees', function (Blueprint $table) {
            $table->unique(['clinic_id', 'user_id'], 'clinic_employees_clinic_user_unique');
            $table->index(['clinic_id', 'status'], 'clinic_employees_clinic_status_index');
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
            $table->unique('clinic_employee_id', 'doctor_profiles_clinic_employee_unique');
        });

        Schema::table('clinic_employee_branches', function (Blueprint $table) {
            $table->index('branch_id', 'clinic_employee_branches_branch_index');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_employee_branches', function (Blueprint $table) {
            $table->dropIndex('clinic_employee_branches_branch_index');
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->index('clinic_employee_id');
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropUnique('doctor_profiles_clinic_employee_unique');
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });

        Schema::table('clinic_employees', function (Blueprint $table) {
            $table->dropUnique('clinic_employees_clinic_user_unique');
            $table->dropIndex('clinic_employees_clinic_status_index');
        });
    }
};
