<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment state comes from payments.
 *
 * `leads.payment_status` had no writer anywhere in the application — every row
 * carried an empty string — while the commercial zone answers the same
 * question properly from the `payments` table, which knows the amount, the
 * reference and whether it settled.
 *
 * A column nothing fills is not a record, it is a place for a stale one to
 * appear later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->after('selected_plan_id');
        });
    }
};
