<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM stops being a dental CRM.
 *
 * Three changes, all of them about the same thing: a healthcare prospect is not
 * one specialty in one building.
 *
 * 1. `healthcare_specialty_masters` — the catalogue. Specialties were a PHP
 *    enum of eight cases, which is fine for a product sold to dentists and
 *    wrong for one sold to a cardiology institute that also runs physiotherapy
 *    and a pathology lab. A table means a specialty can be added without a
 *    deploy, grouped into a category the UI can colour by, and retired without
 *    breaking rows that reference it. `leads.healthcare_specialties` is already
 *    an unconstrained JSON array and stays that way — the catalogue is a
 *    reference for pickers and grouping, deliberately not a foreign key, so a
 *    specialty nobody has catalogued yet does not block a rep mid-call.
 *
 * 2. `leads.facility_type` — what kind of place it is. `organization_type`
 *    already exists and answers a commercial question (chain, hospital,
 *    diagnostic centre). This answers an operational one, and they are not the
 *    same: a solo practice and a forty-five bed hospital can both be
 *    `medical_center`, and they are not remotely the same sale.
 *
 * 3. `lead_branches` gains clinical capacity. A branch already carried a name
 *    and an address; what a rep actually needs to size a deal is how many
 *    doctors work there and how many beds, chairs or theatres it runs. All
 *    nullable, because a physiotherapy branch has no beds and a solo clinic has
 *    no theatres — zero would be a claim, null is the absence of one.
 *
 * `is_main` already exists on `lead_branches` and is not duplicated as
 * `is_main_branch`; a second column meaning the same thing is how two figures
 * on one screen start to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('healthcare_specialty_masters', function (Blueprint $table) {
            $table->id();

            /*
             * The stored identifier. Lower-snake so it is stable across a label
             * change — "IVF/Fertility" may be renamed on screen a dozen times
             * and `ivf_fertility` will still be what the rows hold.
             */
            $table->string('key')->unique();
            $table->string('name');

            /*
             * The grouping the UI colours and filters by: general,
             * maternal_child, surgical_specialty, super_specialty, dental,
             * rehabilitation, ayush, diagnostics.
             */
            $table->string('category')->index();
            $table->string('category_label');

            // Ordering within a category, so a picker reads the way a
            // practitioner would expect rather than alphabetically.
            $table->unsignedSmallInteger('position')->default(0);

            /*
             * Retiring a specialty must not rewrite history. Inactive rows stop
             * appearing in pickers; leads that already name them keep rendering.
             */
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            /*
             * Nullable with no default. A default would silently assert that
             * every prospect recorded before today is a solo clinic, and most of
             * the interesting ones are not.
             */
            $table->string('facility_type')->nullable()->after('organization_type');
            $table->index('facility_type', 'leads_facility_type_index');
        });

        Schema::table('lead_branches', function (Blueprint $table) {
            // Clinical capacity. All nullable — see the class docblock.
            $table->unsignedSmallInteger('doctor_count')->nullable()->after('is_main');
            $table->unsignedSmallInteger('bed_count')->nullable()->after('doctor_count');
            $table->unsignedSmallInteger('operation_theatre_count')->nullable()->after('bed_count');
            /*
             * Dental chairs and physiotherapy bays are the same idea wearing
             * different words: how many patients can be treated at once. One
             * column rather than one per discipline, because the alternative is
             * a new column every time the product is sold to a new specialty.
             */
            $table->unsignedSmallInteger('treatment_station_count')->nullable()->after('operation_theatre_count');
        });
    }

    public function down(): void
    {
        Schema::table('lead_branches', function (Blueprint $table) {
            $table->dropColumn([
                'doctor_count',
                'bed_count',
                'operation_theatre_count',
                'treatment_station_count',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_facility_type_index');
            $table->dropColumn('facility_type');
        });

        Schema::dropIfExists('healthcare_specialty_masters');
    }
};
