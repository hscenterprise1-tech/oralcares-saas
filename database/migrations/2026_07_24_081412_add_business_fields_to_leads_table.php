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
            $table->decimal('expected_revenue', 15, 2)->nullable()->after('priority');
            $table->text('competitors')->nullable()->after('expected_revenue');
            $table->integer('lead_score')->default(0)->after('competitors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['expected_revenue', 'competitors', 'lead_score']);
        });
    }
};
