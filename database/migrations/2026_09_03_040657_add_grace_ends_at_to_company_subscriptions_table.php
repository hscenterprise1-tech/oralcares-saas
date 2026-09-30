<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The end of the grace window, on the row that decides access.
 *
 * `trial_ends_at` already exists here; the seven days after it did not, so
 * there was nowhere to say when a lapsed trial actually loses the door. The
 * subscription is the single source of truth for what a tenant may do, so the
 * boundary belongs on it rather than only on the CRM's trial record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_ends_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropColumn('grace_ends_at');
        });
    }
};
