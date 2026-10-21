<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clinical_procedures` stays the canonical procedure catalog — the steps a
 * treatment is made of, and what can be booked or billed on its own. It gains
 * its specialty, whether it can be billed or booked, and structured metadata.
 * Price leaves the procedure: `catalog_price_versions` holds every price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->string('specialty_key', 50)->nullable()->after('category');
            $table->boolean('is_billable')->default(true)->after('is_active');
            $table->boolean('is_bookable')->default(true)->after('is_billable');
            $table->json('metadata')->nullable()->after('is_bookable');

            $table->unique(['id', 'clinic_id'], 'clinical_procedures_id_clinic_unique');
            $table->index(['clinic_id', 'specialty_key', 'is_active'], 'clinical_procedures_clinic_specialty_index');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->dropIndex('clinical_procedures_clinic_specialty_index');
            $table->dropUnique('clinical_procedures_id_clinic_unique');
            $table->dropColumn(['specialty_key', 'is_billable', 'is_bookable', 'metadata']);
        });
    }
};
