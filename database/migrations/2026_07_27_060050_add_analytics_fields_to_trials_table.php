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
            $table->dateTime('last_login')->nullable();
            $table->integer('users_created')->default(0);
            $table->integer('doctors_count')->default(0);
            $table->integer('patients_count')->default(0);
            $table->integer('bills_count')->default(0);
            $table->decimal('usage_percent', 5, 2)->default(0);
            $table->integer('inactive_days')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropColumn([
                'last_login',
                'users_created',
                'doctors_count',
                'patients_count',
                'bills_count',
                'usage_percent',
                'inactive_days',
            ]);
        });
    }
};
