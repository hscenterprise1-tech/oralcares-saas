<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add user-friendly batch name to marketing contact imports.
     *
     * batch_name: a human-readable label like "Vadodara Dental Clinics — August 2026".
     * Required in the UI but nullable at DB level (existing rows have no name).
     */
    public function up(): void
    {
        Schema::table('marketing_contact_imports', function (Blueprint $table) {
            $table->string('batch_name')->nullable()->after('original_filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_contact_imports', function (Blueprint $table) {
            $table->dropColumn('batch_name');
        });
    }
};
