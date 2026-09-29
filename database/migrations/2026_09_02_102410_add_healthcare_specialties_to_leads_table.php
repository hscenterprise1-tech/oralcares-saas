<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A clinic can practise more than one specialty.
 *
 * `healthcare_specialty` is a single varchar, so a multi-specialty hospital
 * could only ever be recorded as one thing. This adds the plural alongside it
 * rather than replacing it: the old column keeps working for everything that
 * already reads it — the public intake form, the workspace territory label,
 * NextBestActionService — and is backfilled from the new one on write.
 *
 * Nothing is dropped, and no existing row changes meaning. Rows that already
 * carry a single specialty are lifted into the array so the two agree from the
 * first read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('healthcare_specialties')->nullable()->after('healthcare_specialty');
        });

        /*
         * Existing single values become one-element arrays.
         *
         * They were written against ClinicType's vocabulary, which this new
         * column does not share, so they are carried across verbatim rather
         * than mapped — the value a person entered is still the truest record
         * of what they meant, and the form will offer the new vocabulary from
         * here on.
         */
        DB::table('leads')
            ->whereNotNull('healthcare_specialty')
            ->where('healthcare_specialty', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($leads) {
                foreach ($leads as $lead) {
                    DB::table('leads')
                        ->where('id', $lead->id)
                        ->update(['healthcare_specialties' => json_encode([$lead->healthcare_specialty])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('healthcare_specialties');
        });
    }
};
