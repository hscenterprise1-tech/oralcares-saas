<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `max_users` already means every clinic login, doctors included —
 * `ResourceLimitService::getCurrentUserCount()` counts every
 * `ClinicMembership` on the clinic, and `getCurrentDoctorCount()` is a
 * separate, overlapping sub-count of just the Doctor-role ones. Redefining
 * `max_users` to mean "non-doctor seats" would have silently changed
 * enforcement for every existing subscription rather than simply adding a
 * cap, so this is a new, named sub-cap instead: `max_users` stays the
 * ceiling, `max_doctors` and `max_staff` are named slices under it.
 *
 * Nullable, matching `additional_user_price`'s precedent — a plan that
 * hasn't set a distinct staff sub-cap has none, not a zero that would block
 * every non-doctor hire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_plans', function (Blueprint $table) {
            $table->unsignedInteger('max_staff')->nullable()->after('max_doctors');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('max_staff')->nullable()->after('max_doctors');
        });
    }

    public function down(): void
    {
        Schema::table('company_plans', function (Blueprint $table) {
            $table->dropColumn('max_staff');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropColumn('max_staff');
        });
    }
};
