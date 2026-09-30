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
        Schema::table('company_plans', function (Blueprint $table) {
            $table->boolean('is_trial_available')->default(true)->after('is_active');
            $table->unsignedInteger('trial_duration_days')->default(15)->after('is_trial_available');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_plans', function (Blueprint $table) {
            $table->dropColumn(['is_trial_available', 'trial_duration_days']);
        });
    }
};
