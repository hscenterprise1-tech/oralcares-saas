<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Focused performance indexes for Customer Success and Lifecycle query patterns:
     * - organizations: role-scoped name lookup and role-scoped assigned rep filtering
     * - company_subscriptions: status window filtering, organization subscription lookups, and renewal horizons
     * - onboarding_progress: status-filtered progress checks per customer
     * - activities: subject activity timeline lookup ordered by recency
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->index(['role', 'name'], 'organizations_role_name_index');
            $table->index(['role', 'assigned_user_id'], 'organizations_role_assigned_user_id_index');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->index(['status', 'ends_at'], 'company_subscriptions_status_ends_at_index');
            $table->index(['organization_id', 'status', 'ends_at'], 'company_subscriptions_org_status_ends_at_index');
            $table->index(['ends_at'], 'company_subscriptions_ends_at_index');
        });

        Schema::table('onboarding_progress', function (Blueprint $table) {
            $table->index(['status', 'organization_id'], 'onboarding_progress_status_org_index');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activities_subject_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('organizations_role_name_index');
            $table->dropIndex('organizations_role_assigned_user_id_index');
        });

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropIndex('company_subscriptions_status_ends_at_index');
            $table->dropIndex('company_subscriptions_org_status_ends_at_index');
            $table->dropIndex('company_subscriptions_ends_at_index');
        });

        Schema::table('onboarding_progress', function (Blueprint $table) {
            $table->dropIndex('onboarding_progress_status_org_index');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_subject_created_at_index');
        });
    }
};
