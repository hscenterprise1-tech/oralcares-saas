<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full treatment-plan lifecycle: draft → proposed → accepted → active
 * (first treatment done) ⇄ paused → completed, or declined / cancelled.
 * Each terminal or paused state keeps when and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('status_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'declined_at', 'cancelled_at', 'status_reason']);
        });
    }
};
