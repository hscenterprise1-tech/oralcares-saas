<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's dashboard choices per staff role: which widgets a role's
 * dashboard hides. It can only hide; what a role may see at all is still
 * decided by its permissions (see `DashboardVisibility`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->json('dashboard_config')->nullable()->after('patient_flow_config');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('dashboard_config');
        });
    }
};
