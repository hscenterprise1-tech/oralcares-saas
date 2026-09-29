<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer's health and churn risk came from.
 *
 * Both columns exist and neither was ever computed — a customer success rep was
 * expected to type them. At eight accounts that is a judgement call; at a
 * hundred and twenty thousand it means every account keeps whatever it was
 * created with, and "Low" stops meaning anything.
 *
 * The nightly projection now derives both from signals the system already
 * records. These two columns are what keeps a human's decision from being
 * overwritten by it: once somebody sets a value by hand the stamp is written,
 * and the job leaves that field alone until the override is cleared.
 *
 * Nullable and null by default, so every existing row reads as "derived" and
 * the first nightly pass fills them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('health_score_overridden_at')->nullable()->after('health_score');
            $table->timestamp('churn_risk_overridden_at')->nullable()->after('churn_risk');

            /*
             * Who last overrode, so the timeline entry and the tooltip on the
             * account can name them rather than saying "somebody".
             */
            $table->foreignId('metrics_overridden_by')->nullable()->after('churn_risk_overridden_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('metrics_overridden_by');
            $table->dropColumn(['health_score_overridden_at', 'churn_risk_overridden_at']);
        });
    }
};
