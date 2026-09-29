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
        Schema::table('patients', function (Blueprint $table) {
            // The directory's default (no-search) listing is `WHERE clinic_id = ?
            // ORDER BY created_at DESC` — without this, MySQL narrows to the
            // clinic via `patients_clinic_uhid_unique` but still has to filesort
            // every one of that clinic's patients to apply the order, which gets
            // slower as a clinic's patient count grows into the thousands.
            $table->index(['clinic_id', 'created_at'], 'patients_clinic_id_created_at_index');

            // `phone` has no index at all today — the directory search and any
            // exact/prefix phone lookup (duplicate-detection, reception lookup)
            // both do a full per-clinic scan without it.
            $table->index('phone', 'patients_phone_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_clinic_id_created_at_index');
            $table->dropIndex('patients_phone_index');
        });
    }
};
