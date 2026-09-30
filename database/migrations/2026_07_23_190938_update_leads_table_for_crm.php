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
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->dropColumn('clinic_id');

            $table->foreignId('organization_id')->nullable()->after('assigned_team_id')->constrained('organizations')->nullOnDelete();
            $table->string('priority')->default('medium')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            $table->dropColumn('priority');

            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
        });
    }
};
