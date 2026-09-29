<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One clinic per staff link, enforced by the database:
 *
 *   membership branch access  (branch_clinic_membership)
 *   staff branch placement    (clinic_employee_branches)
 *   practitioner schedule     (practitioner_schedules)
 *   practitioner profile      (doctor_profiles)
 *
 * Each row carries `clinic_id`, and composite foreign keys tie
 * (membership, clinic), (staff record, clinic) and (branch, clinic) to rows
 * that really have that clinic. A link between one clinic's membership or
 * staff and another clinic's branch cannot be stored, whichever code path
 * tries.
 *
 * `clinic_id` on the two link tables is filled from the membership / staff
 * record. Any existing cross-clinic row stops the migration with its ids;
 * nothing is changed or removed to make it pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseCrossClinicRows();

        foreach (['branches', 'clinic_memberships', 'clinic_employees'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unique(['id', 'clinic_id'], "{$table}_id_clinic_unique");
            });
        }

        $this->addClinicColumn('branch_clinic_membership', 'clinic_membership_id', 'clinic_memberships');
        $this->addClinicColumn('clinic_employee_branches', 'clinic_employee_id', 'clinic_employees');

        Schema::table('branch_clinic_membership', function (Blueprint $table) {
            $table->foreign(['clinic_membership_id', 'clinic_id'], 'bcm_membership_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_memberships')->cascadeOnDelete();
            $table->foreign(['branch_id', 'clinic_id'], 'bcm_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->cascadeOnDelete();
        });

        Schema::table('clinic_employee_branches', function (Blueprint $table) {
            $table->foreign(['clinic_employee_id', 'clinic_id'], 'ceb_employee_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_employees')->cascadeOnDelete();
            $table->foreign(['branch_id', 'clinic_id'], 'ceb_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->cascadeOnDelete();
        });

        Schema::table('practitioner_schedules', function (Blueprint $table) {
            $table->foreign(['clinic_employee_id', 'clinic_id'], 'ps_employee_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_employees')->cascadeOnDelete();
            $table->foreign(['branch_id', 'clinic_id'], 'ps_branch_same_clinic_fk')->references(['id', 'clinic_id'])->on('branches')->cascadeOnDelete();
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->foreign(['clinic_employee_id', 'clinic_id'], 'dp_employee_same_clinic_fk')->references(['id', 'clinic_id'])->on('clinic_employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', fn (Blueprint $table) => $table->dropForeign('dp_employee_same_clinic_fk'));
        Schema::table('practitioner_schedules', function (Blueprint $table) {
            $table->dropForeign('ps_employee_same_clinic_fk');
            $table->dropForeign('ps_branch_same_clinic_fk');
        });

        foreach (['branch_clinic_membership' => ['bcm_membership_same_clinic_fk', 'bcm_branch_same_clinic_fk'], 'clinic_employee_branches' => ['ceb_employee_same_clinic_fk', 'ceb_branch_same_clinic_fk']] as $table => $keys) {
            Schema::table($table, function (Blueprint $blueprint) use ($keys) {
                foreach ($keys as $key) {
                    $blueprint->dropForeign($key);
                }
            });
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('clinic_id'));
        }

        foreach (['clinic_employees', 'clinic_memberships', 'branches'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique("{$table}_id_clinic_unique"));
        }
    }

    private function addClinicColumn(string $table, string $ownerColumn, string $ownerTable): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('clinic_id')->nullable()->after('id'));

        DB::table($table)->update([
            'clinic_id' => DB::raw("(SELECT {$ownerTable}.clinic_id FROM {$ownerTable} WHERE {$ownerTable}.id = {$table}.{$ownerColumn})"),
        ]);

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('clinic_id')->nullable(false)->change());
    }

    private function refuseCrossClinicRows(): void
    {
        $crossClinic = [
            'membership branch access' => DB::table('branch_clinic_membership')
                ->join('clinic_memberships', 'clinic_memberships.id', '=', 'branch_clinic_membership.clinic_membership_id')
                ->join('branches', 'branches.id', '=', 'branch_clinic_membership.branch_id')
                ->where(fn ($query) => $query->whereNull('branches.clinic_id')->orWhereColumn('branches.clinic_id', '!=', 'clinic_memberships.clinic_id'))
                ->pluck('branch_clinic_membership.id'),
            'staff branch placement' => DB::table('clinic_employee_branches')
                ->join('clinic_employees', 'clinic_employees.id', '=', 'clinic_employee_branches.clinic_employee_id')
                ->join('branches', 'branches.id', '=', 'clinic_employee_branches.branch_id')
                ->where(fn ($query) => $query->whereNull('branches.clinic_id')->orWhereColumn('branches.clinic_id', '!=', 'clinic_employees.clinic_id'))
                ->pluck('clinic_employee_branches.id'),
            'practitioner schedule' => DB::table('practitioner_schedules')
                ->join('clinic_employees', 'clinic_employees.id', '=', 'practitioner_schedules.clinic_employee_id')
                ->join('branches', 'branches.id', '=', 'practitioner_schedules.branch_id')
                ->where(fn ($query) => $query->whereColumn('branches.clinic_id', '!=', 'practitioner_schedules.clinic_id')
                    ->orWhereColumn('clinic_employees.clinic_id', '!=', 'practitioner_schedules.clinic_id'))
                ->pluck('practitioner_schedules.id'),
        ];

        $found = array_filter(array_map(fn ($ids) => $ids->all(), $crossClinic));

        if ($found !== []) {
            throw new RuntimeException('Cross-clinic staff links exist; resolve them by hand before this migration can run: '.json_encode($found));
        }
    }
};
