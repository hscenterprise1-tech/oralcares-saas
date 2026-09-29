<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thirteen tables and one column that nothing reads.
 *
 * Three groups, each dead for a different reason:
 *
 * 1. The deleted model generation — `company_demos`, `company_opportunities`,
 *    `company_trials`, `company_trial_extensions`, `company_sales_activities`,
 *    `company_reminders`, `company_provisioning_processes` and
 *    `company_provisioning_activities`. Every one of these lost its model in the
 *    CRM rebuild and was superseded by `demos`, `leads`, `trials`, `activities`
 *    and `provisioning_audits`. All eight are empty.
 *
 * 2. The third activity system — `entity_activities`, `entity_notes` and
 *    `entity_tasks`, behind `app/Platform/Entities`. That directory had zero
 *    references outside itself and is removed with this change. It sat alongside
 *    `activities`/`tasks` (CRM) and `company_employee_activities` (People) as a
 *    third unused model of the same idea.
 *
 * 3. Superseded CRM tables — `lead_automation_rules`, reachable only from
 *    `WorkflowAutomationEngine` which nothing ever called, and
 *    `lead_whatsapp_messages`, whose history was copied into `activities` by
 *    `2026_09_03_001531_preserve_lead_whatsapp_history_as_activities`. The deal
 *    page has read the Marketing conversation since Phase 2.
 *
 * `lead_branches.deleted_at` goes with them: `syncLeadBranches()` force-deletes
 * and recreates on every save, so the column has never held a value.
 *
 * One live table had to be detached first. `company_subscriptions.opportunity_id`
 * carried a foreign key into `company_opportunities` — the last thing holding
 * that table up. The column is null on every row and is named nowhere in the
 * application (the `expansion_opportunity` matches in Customer Success are a
 * different field), so it is dropped with its constraint rather than left
 * pointing at nothing.
 *
 * The drop order below is deliberate and MySQL enforces it: children before
 * parents. SQLite does not, which is why this had to be found against the real
 * schema rather than the test one.
 *
 * `down()` recreates nothing. Restoring empty tables would not restore what the
 * drop removed, and `lead_whatsapp_messages` held 25 rows that live on in
 * `activities`. The reversal for this migration is a database restore — the same
 * position `2026_09_10_051913_drop_legacy_crm_tables` took.
 */
return new class extends Migration
{
    /**
     * Children first, then parents. `company_opportunities` is last of its
     * cluster because five tables pointed at it.
     *
     * @var list<string>
     */
    private const DROPPED_TABLES = [
        // Children of company_trials / company_opportunities.
        'company_trial_extensions',
        'company_trials',
        'company_sales_activities',
        'company_reminders',

        // Superseded by `provisioning_audits`.
        'company_provisioning_activities',
        'company_provisioning_processes',

        // Superseded by `demos` and `leads`.
        'company_demos',
        'company_opportunities',

        // app/Platform/Entities — a third, unreferenced activity system.
        'entity_activities',
        'entity_notes',
        'entity_tasks',

        // Reachable only from WorkflowAutomationEngine, which nothing called.
        'lead_automation_rules',

        // History preserved into `activities` in September.
        'lead_whatsapp_messages',
    ];

    public function up(): void
    {
        /*
         * The one live dependency. Dropped before its parent, and only if it is
         * still there — this migration has to be safe to re-run after a partial
         * failure on a foreign key.
         */
        if (Schema::hasColumn('company_subscriptions', 'opportunity_id')) {
            Schema::table('company_subscriptions', function (Blueprint $table) {
                /*
                 * By column, not by constraint name. MySQL resolves it to
                 * `company_subscriptions_opportunity_id_foreign`; SQLite has no
                 * drop-foreign-key-by-name at all and folds it into the table
                 * rebuild that `dropColumn` already performs.
                 */
                $table->dropForeign(['opportunity_id']);
                $table->dropColumn('opportunity_id');
            });
        }

        foreach (self::DROPPED_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('lead_branches', 'deleted_at')) {
            Schema::table('lead_branches', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }

    public function down(): void
    {
        // Irreversible by design — see the class docblock.
    }
};
