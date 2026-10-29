<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was known about the patient's allergies when a prescription was
 * written, and who reviewed it (`ClinicalPrescriptionService::reviewAllergies`).
 *
 * - `allergy_review`: the snapshot — status (documented / none_known /
 *   not_recorded), the allergies as recorded, and their fingerprint;
 * - `allergy_reviewed_at` / `allergy_reviewed_by`: the clinician's
 *   acknowledgement, required before signing when allergies are documented.
 *
 * Existing prescriptions keep null: nothing is invented for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->json('allergy_review')->nullable()->after('signature_hash');
            $table->timestamp('allergy_reviewed_at')->nullable()->after('allergy_review');
            $table->foreignId('allergy_reviewed_by')->nullable()->after('allergy_reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergy_reviewed_by');
            $table->dropColumn(['allergy_review', 'allergy_reviewed_at']);
        });
    }
};
