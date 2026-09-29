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
        Schema::table('trials', function (Blueprint $table) {
            $table->dropColumn(['doctors_count', 'patients_count', 'bills_count']);
            $table->json('usage_metrics')->nullable()->after('users_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropColumn('usage_metrics');
            $table->integer('doctors_count')->default(0);
            $table->integer('patients_count')->default(0);
            $table->integer('bills_count')->default(0);
        });
    }
};
