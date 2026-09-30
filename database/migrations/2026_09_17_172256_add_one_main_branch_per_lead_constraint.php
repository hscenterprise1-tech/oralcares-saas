<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "One main branch per lead" has so far been enforced only by
 * `LeadBranch`'s own `saved` hook (demotes every sibling after a save where
 * `is_main = true`) plus request-layer validation in
 * `StoreLeadRequest`/`UpdateLeadRequest`. The base migration's own comment
 * already recorded why no database constraint was added at the time — MySQL
 * has no partial/filtered unique index. The same generated-column pattern
 * used for `contacts.is_primary` closes that gap here: NULL for every branch
 * that isn't the main one (MySQL treats each NULL as distinct, so any number
 * of non-main branches per lead still coexist), and the lead id for the one
 * that is — so two main branches on the same lead collide at the database,
 * not only in whichever caller remembered to check first.
 *
 * `lead_branches` carries no `deleted_at` (dropped in
 * 2026_09_14_152709_drop_orphan_crm_tables_and_dead_columns — the sync
 * service force-deletes and recreates rather than soft-deleting), so unlike
 * the contacts version this generated column needs no soft-delete guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_branches', function (Blueprint $table) {
            // Virtual, not stored — see the sibling contacts migration for
            // why: MySQL 8's INPLACE algorithm refuses a STORED generated
            // column on a table that already carries a foreign key.
            $table->unsignedBigInteger('main_branch_lead_id')
                ->virtualAs('CASE WHEN is_main = 1 THEN lead_id ELSE NULL END')
                ->nullable()
                ->after('is_main');
        });

        Schema::table('lead_branches', function (Blueprint $table) {
            $table->unique('main_branch_lead_id', 'lead_branches_one_main_per_lead');
        });
    }

    public function down(): void
    {
        Schema::table('lead_branches', function (Blueprint $table) {
            $table->dropUnique('lead_branches_one_main_per_lead');
        });

        Schema::table('lead_branches', function (Blueprint $table) {
            $table->dropColumn('main_branch_lead_id');
        });
    }
};
