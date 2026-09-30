<?php

use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tasks` has never carried its own tenant column — scoping rode entirely on
 * `assigned_user_id`, so a task with no assignee (an automation-created one,
 * or one a rep unassigned) was invisible to every non-admin CrmScope read and,
 * separately, `TaskPolicy` had no tenant check of its own at all. This closes
 * both: the column here, the policy and query fixes in the same-day app code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('creator_id')->constrained('organizations')->nullOnDelete();
        });

        $leadMorph = Lead::class;
        $organizationMorph = Organization::class;

        /*
         * Backfilled in the same order the model derives it going forward
         * (see Task::deriveOrganizationId()): the taskable's own tenant
         * first, the assignee's tenant next, the creator's last. Both the
         * registered morph alias ('lead'/'organization') and the bare FQCN
         * are matched — a known writer stamped the raw class name before
         * this migration's sibling fix, and historical rows still carry it.
         * A task nothing can resolve stays null — CrmScope's fail-closed
         * helper then correctly hides it from everyone but a master admin,
         * rather than this backfill guessing an owner it cannot prove.
         */
        DB::table('tasks')
            ->whereIn('taskable_type', ['lead', $leadMorph])
            ->whereNotNull('taskable_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select leads.organization_id from leads where leads.id = tasks.taskable_id)'
                ),
            ]);

        /*
         * `organizations` rows serve two roles — the one provider tenant, and
         * every customer account won into it — so a task filed against the
         * customer side still belongs to the provider who manages that
         * account, traced through the lead that converted into it. Falls
         * back to the row's own id only when it is a real, existing
         * organization with no lead tracing to it (the provider organization
         * itself); a `taskable_id` that matches no row at all — a dangling
         * reference — is left null rather than guessed.
         */
        DB::table('tasks')
            ->whereIn('taskable_type', ['organization', $organizationMorph])
            ->whereNotNull('taskable_id')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    'coalesce('.
                    '(select leads.organization_id from leads where leads.customer_organization_id = tasks.taskable_id limit 1), '.
                    '(select organizations.id from organizations where organizations.id = tasks.taskable_id)'.
                    ')'
                ),
            ]);

        DB::table('tasks')
            ->where('taskable_type', MarketingContact::class)
            ->whereNotNull('taskable_id')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select marketing_contacts.organization_id from marketing_contacts where marketing_contacts.id = tasks.taskable_id)'
                ),
            ]);

        DB::table('tasks')
            ->whereNull('organization_id')
            ->whereNotNull('assigned_user_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select users.organization_id from users where users.id = tasks.assigned_user_id)'
                ),
            ]);

        DB::table('tasks')
            ->whereNull('organization_id')
            ->whereNotNull('creator_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select users.organization_id from users where users.id = tasks.creator_id)'
                ),
            ]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'tasks_organization_id_status_index');
        });
    }

    public function down(): void
    {
        // The FK constraint must go before the index that satisfies it, or
        // MySQL refuses to drop the index while the constraint still needs it.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_organization_id_status_index');
            $table->dropColumn('organization_id');
        });
    }
};
