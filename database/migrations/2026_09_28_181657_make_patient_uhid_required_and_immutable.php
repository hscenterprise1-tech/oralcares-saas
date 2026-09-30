<?php

use App\Models\Clinic;
use App\Services\Patient\UhidGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1 — the UHID is the patient's lifetime identity inside a clinic.
 *
 * `patients_clinic_uhid_unique` already guarantees uniqueness, but the column
 * was nullable (MySQL treats every NULL as distinct, so any number of patients
 * could have no UHID). Every patient missing one is given a real UHID from
 * `UhidGenerator` first, then the column is made NOT NULL. Immutability is
 * enforced in `Patient::booted()`, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $generator = app(UhidGenerator::class);

        DB::table('patients')
            ->where(fn ($q) => $q->whereNull('uhid')->orWhere('uhid', ''))
            ->orderBy('id')
            ->get(['id', 'clinic_id'])
            ->each(function (object $row) use ($generator): void {
                $clinic = Clinic::withoutGlobalScopes()->find($row->clinic_id);

                DB::table('patients')->where('id', $row->id)->update([
                    'uhid' => $generator->generate($clinic),
                ]);
            });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('uhid')->nullable(false)->change();
        });
    }

    /**
     * Generated UHIDs are real identities and stay; only the constraint is lifted.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('uhid')->nullable()->change();
        });
    }
};
