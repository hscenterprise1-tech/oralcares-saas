<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practice areas (sub-specialties such as Orthodontics or Sports
 * Rehabilitation, defined in `config/clinic_specialties.php`).
 *
 * - `clinical_specialties.practice_area_key`: the organisation's specialty
 *   catalogue entry that stands for a registry practice area. A
 *   practitioner's primary and additional specialties already point at this
 *   catalogue, so their areas need no second assignment table. Entries a
 *   clinic typed itself ("Implantology") stay unkeyed and informational.
 *   One entry per area per organisation.
 * - `patient_visits.practice_area_key`: the area the visit was resolved to
 *   from its practitioner, so Patient 360 can show each care stream as
 *   "Dental · Orthodontics" long after the practitioner's profile changes.
 *
 * Both nullable: existing rows are read through the registry's aliases until
 * `practitioners:backfill-practice-areas` keys them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_specialties', function (Blueprint $table) {
            $table->string('practice_area_key', 60)->nullable()->after('name');
            $table->unique(['organization_id', 'practice_area_key'], 'clinical_specialties_org_area_unique');
        });

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->string('practice_area_key', 60)->nullable()->after('clinic_specialty_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_visits', fn (Blueprint $table) => $table->dropColumn('practice_area_key'));

        // MySQL may let the new unique index serve the organization foreign
        // key and drop its own; give it back before the unique goes.
        if (! Schema::hasIndex('clinical_specialties', 'clinical_specialties_organization_id_foreign')) {
            Schema::table('clinical_specialties', fn (Blueprint $table) => $table->index('organization_id', 'clinical_specialties_organization_id_foreign'));
        }

        Schema::table('clinical_specialties', function (Blueprint $table) {
            $table->dropUnique('clinical_specialties_org_area_unique');
            $table->dropColumn('practice_area_key');
        });
    }
};
