<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Per-clinic, not per-organization: `Clinic/Admin/SettingsController`
        // already stores branding (display name, logo) as columns directly
        // on `clinics` rather than on `organizations`, because one
        // organization can run several clinics that each want their own
        // identity — a dental clinic and a physio clinic under the same
        // franchise plausibly want different mandatory registration
        // fields too. `clinic_settings` (a generic group/key/value table)
        // would have been the natural place for this, but it was renamed
        // to `organization_settings` in `align_schemas_with_organization_
        // entity` — reusing that name here would recreate exactly the
        // table that rename deliberately retired. A single JSON column is
        // simpler than a new EAV table for what is, today, one setting.
        Schema::table('clinics', function (Blueprint $table) {
            $table->json('patient_field_config')->nullable()->after('use_clinic_logo_for_invoice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('patient_field_config');
        });
    }
};
