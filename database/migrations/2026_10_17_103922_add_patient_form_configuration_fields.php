<?php

use App\Services\Clinical\ClinicalCatalogue;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient form configuration (P1.2), additive only:
 *
 * - `patient_profiles.locality`: the one address part with no column yet
 *   (line, city, state, pincode and country already exist).
 * - Starting lists for the two new clinical-entry kinds, past surgery and
 *   family history, in the shared catalogue. Rows already there are left
 *   alone; nothing existing is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('patient_profiles', 'locality')) {
            Schema::table('patient_profiles', function (Blueprint $table) {
                $table->string('locality', 120)->nullable()->after('address');
            });
        }

        $now = now();
        DB::table('clinical_catalogue_items')->insertOrIgnore(array_map(fn (array $item) => [
            ...$item,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], DefaultClinicalCatalogue::historyItems()));

        app(ClinicalCatalogue::class)->forgetGlobal();
    }

    public function down(): void
    {
        foreach (collect(DefaultClinicalCatalogue::historyItems())->groupBy('kind') as $kind => $items) {
            DB::table('clinic_catalogue_settings')->where('kind', $kind)->whereIn('key', $items->pluck('key'))->delete();
            DB::table('clinical_catalogue_items')->where('kind', $kind)->whereIn('key', $items->pluck('key'))->delete();
        }
        if (Schema::hasColumn('patient_profiles', 'locality')) {
            Schema::table('patient_profiles', fn (Blueprint $table) => $table->dropColumn('locality'));
        }

        app(ClinicalCatalogue::class)->forgetGlobal();
    }
};
