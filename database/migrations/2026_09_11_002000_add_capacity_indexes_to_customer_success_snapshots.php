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
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->index('branch_utilization_pct', 'cs_snapshots_branch_util_idx');
            $table->index('expansion_opportunity', 'cs_snapshots_expansion_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->dropIndex('cs_snapshots_branch_util_idx');
            $table->dropIndex('cs_snapshots_expansion_idx');
        });
    }
};
