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
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->foreignId('company_plan_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_plan_id');
        });
    }
};
