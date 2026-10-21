<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Needs a tooth" was a dental assumption in the core (`requires_tooth`).
 * The core now knows only targets that a specialty extension declares
 * (config/clinic_specialties.php — dental declares `tooth`). Existing
 * flags are copied into `settings.targets` (versions) and
 * `metadata.targets` (procedures). The old columns stay, unused, until
 * retirement; nothing is removed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog_treatment_versions')->where('requires_tooth', true)->orderBy('id')->each(function ($version) {
            $settings = json_decode((string) $version->settings, true) ?: [];
            $settings['targets'] = array_values(array_unique([...($settings['targets'] ?? []), 'tooth']));
            DB::table('catalog_treatment_versions')->where('id', $version->id)->update(['settings' => json_encode($settings)]);
        });

        DB::table('clinical_procedures')->where('requires_tooth', true)->orderBy('id')->each(function ($procedure) {
            $metadata = json_decode((string) $procedure->metadata, true) ?: [];
            $metadata['targets'] = array_values(array_unique([...($metadata['targets'] ?? []), 'tooth']));
            DB::table('clinical_procedures')->where('id', $procedure->id)->update(['metadata' => json_encode($metadata)]);
        });
    }

    public function down(): void
    {
        // The copied targets are left in place; the source flags were never changed.
    }
};
