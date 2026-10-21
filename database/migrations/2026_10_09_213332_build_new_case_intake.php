<?php

use App\Services\Clinical\ClinicalCatalogue;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New Case / Repeat Case intake.
 *
 * - Catalogue items and clinic settings gain `practice_area_keys`: a visit
 *   reason can belong to Orthodontics without belonging to all of Dental.
 *   The starting visit reasons and alerts are added to the shared catalogue.
 * - `patient_visits` gains the reason's catalogue key (the label or "Other"
 *   text stays in `visit_reason`) and the vitals taken at the desk, which
 *   the consultation opened from the visit starts with.
 * - `patient_profiles.allergy_status`: "no known allergies" is a recorded
 *   fact, distinct from "not asked" (null). `patients` is not altered.
 * - `intake_submissions`: one row per registration/check-in request key, so
 *   a double click or a retried request returns the first result instead of
 *   registering twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_catalogue_items', function (Blueprint $table) {
            $table->json('practice_area_keys')->nullable()->after('specialty_keys');
        });
        Schema::table('clinic_catalogue_settings', function (Blueprint $table) {
            $table->json('practice_area_keys')->nullable()->after('specialty_keys');
        });

        $now = now();
        DB::table('clinical_catalogue_items')->insertOrIgnore(array_map(fn (array $item) => [
            ...$item,
            'specialty_keys' => $item['specialty_keys'] === null ? null : json_encode($item['specialty_keys']),
            'practice_area_keys' => $item['practice_area_keys'] === null ? null : json_encode($item['practice_area_keys']),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], DefaultClinicalCatalogue::intakeItems()));

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->string('visit_reason_key', 60)->nullable()->after('visit_reason');
            $table->json('intake_vitals')->nullable()->after('visit_reason_key');
        });

        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->string('allergy_status', 16)->nullable()->after('emergency_contact_phone');
        });

        Schema::create('intake_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 80);
            $table->string('action', 20);
            $table->unsignedSmallInteger('status')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'idempotency_key'], 'intake_submissions_clinic_key_unique');
            $table->index('created_at');
        });

        app(ClinicalCatalogue::class)->forgetGlobal();
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_submissions');
        Schema::table('patient_profiles', fn (Blueprint $table) => $table->dropColumn('allergy_status'));
        Schema::table('patient_visits', fn (Blueprint $table) => $table->dropColumn(['visit_reason_key', 'intake_vitals']));

        $keys = collect(DefaultClinicalCatalogue::intakeItems());
        foreach ($keys->groupBy('kind') as $kind => $items) {
            DB::table('clinic_catalogue_settings')->where('kind', $kind)->whereIn('key', $items->pluck('key'))->delete();
            DB::table('clinical_catalogue_items')->where('kind', $kind)->whereIn('key', $items->pluck('key'))->delete();
        }
        DB::table('clinic_catalogue_settings')->where('kind', DefaultClinicalCatalogue::KIND_VISIT_REASON)->delete();

        Schema::table('clinic_catalogue_settings', fn (Blueprint $table) => $table->dropColumn('practice_area_keys'));
        Schema::table('clinical_catalogue_items', fn (Blueprint $table) => $table->dropColumn('practice_area_keys'));

        app(ClinicalCatalogue::class)->forgetGlobal();
    }
};
