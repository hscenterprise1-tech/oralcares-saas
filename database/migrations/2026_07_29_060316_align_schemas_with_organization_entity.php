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
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
        });

        Schema::rename('clinic_settings', 'organization_settings');

        Schema::table('organization_settings', function (Blueprint $table) {
            $table->renameColumn('clinic_id', 'organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('organization_settings', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->renameColumn('organization_id', 'clinic_id');
            $table->foreign('clinic_id')->references('id')->on('clinics')->cascadeOnDelete();
        });

        Schema::rename('organization_settings', 'clinic_settings');
    }
};
