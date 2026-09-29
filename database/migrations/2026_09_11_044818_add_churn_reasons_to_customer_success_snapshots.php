<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an account is at risk, not merely that it is.
 *
 * A churn risk with no reasoning is a colour, and a rep opening an account to
 * find "High" with nothing behind it has to reconstruct the case themselves.
 * `ChurnRiskService` already produces the list of signals it counted; this is
 * where the nightly pass keeps them, so the account page can show the working
 * rather than the verdict alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->json('churn_reasons')->nullable()->after('churn_risk');
        });
    }

    public function down(): void
    {
        Schema::table('customer_success_snapshots', function (Blueprint $table) {
            $table->dropColumn('churn_reasons');
        });
    }
};
