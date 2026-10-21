<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investigations on the one order model: `clinical_orders` (the request, from
 * a consultation) → `clinical_order_items` (each test or study, with its own
 * fulfilment state) → `diagnostic_results` (the report, reviewed by a
 * clinician).
 *
 * - Items and results become clinic-owned and can only hang off a parent of
 *   the same clinic (composite foreign keys).
 * - An item moves ordered → collected → resulted → reviewed, or is
 *   cancelled; each step records who and when.
 * - One result per item. Its files are patient documents filed against the
 *   item (`attached_documents.clinical_order_item_id`), so they go through
 *   the same malware-scanned upload as every other document.
 * - `clinic_investigations` is the clinic's own test list, learnt from what
 *   its doctors order (as the formulary is from prescriptions).
 *
 * Legacy item statuses map onto the lifecycle: pending → ordered,
 * in_progress → collected, completed → reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 40)->nullable();
            $table->string('category', 20);
            $table->string('lookup_key', 200);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->unique(['clinic_id', 'lookup_key']);
            $table->index(['clinic_id', 'is_active', 'use_count']);
        });

        Schema::table('clinical_orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('orderer_id')->nullable()->after('ordered_by')->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->unique(['id', 'clinic_id'], 'clinical_orders_id_clinic_unique');
            $table->index(['clinic_id', 'branch_id', 'status'], 'clinical_orders_branch_status_index');
        });

        DB::table('clinical_orders')->whereNotNull('encounter_id')->update([
            'branch_id' => DB::raw('(select branch_id from clinical_encounters where clinical_encounters.id = clinical_orders.encounter_id)'),
        ]);

        Schema::table('clinical_order_items', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id');
            $table->foreignId('investigation_id')->nullable()->after('clinical_order_id')->constrained('clinic_investigations')->nullOnDelete();
            $table->string('category', 20)->default('lab')->after('item_name');
            $table->string('body_site', 100)->nullable()->after('category');
            $table->string('external_provider', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('resulted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
        });

        DB::table('clinical_order_items')->update([
            'clinic_id' => DB::raw('(select clinic_id from clinical_orders where clinical_orders.id = clinical_order_items.clinical_order_id)'),
        ]);
        foreach (['pending' => 'ordered', 'in_progress' => 'collected', 'completed' => 'reviewed'] as $from => $to) {
            DB::table('clinical_order_items')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('clinical_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
            $table->string('status')->default('ordered')->change();
            $table->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
            $table->foreign(['clinical_order_id', 'clinic_id'], 'clinical_order_items_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('clinical_orders')->cascadeOnDelete();
            $table->unique(['id', 'clinic_id'], 'clinical_order_items_id_clinic_unique');
            $table->index(['clinic_id', 'status', 'category'], 'clinical_order_items_worklist_index');
        });

        DB::table('diagnostic_results')->whereNull('clinic_id')->update([
            'clinic_id' => DB::raw('(select clinic_id from clinical_order_items where clinical_order_items.id = diagnostic_results.clinical_order_item_id)'),
        ]);

        Schema::table('diagnostic_results', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('result_type');
            $table->boolean('is_abnormal')->default(false)->after('summary');
            $table->text('review_note')->nullable()->after('verified_at');
            $table->foreignId('reporter_id')->nullable()->after('reported_by')->constrained('clinic_employees')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->after('verified_by')->constrained('clinic_employees')->restrictOnDelete();
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();

            $table->unique('clinical_order_item_id', 'diagnostic_results_item_unique');
            $table->foreign(['clinical_order_item_id', 'clinic_id'], 'diagnostic_results_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('clinical_order_items')->cascadeOnDelete();
        });

        Schema::table('attached_documents', function (Blueprint $table) {
            $table->foreignId('clinical_order_item_id')->nullable()->after('encounter_id')->constrained('clinical_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attached_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('clinical_order_item_id'));

        // MySQL serves a foreign key from any index that leads with its column
        // and drops its own: every single-column key index is restored before
        // the composite or unique index standing in for it is dropped, and
        // every foreign key is dropped before the indexes.
        $ensureIndex = function (string $table, string $column): void {
            $covered = collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['columns'] === [$column] && ! $index['unique']);
            if (! $covered) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, "{$table}_{$column}_foreign"));
            }
        };
        $dropIndexes = function (string $table, array $names): void {
            $existing = array_column(Schema::getIndexes($table), 'name');
            Schema::table($table, function (Blueprint $blueprint) use ($names, $existing) {
                foreach (array_intersect($names, $existing) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        };

        Schema::table('diagnostic_results', function (Blueprint $table) {
            $table->dropForeign('diagnostic_results_same_clinic_fk');
            $table->dropForeign(['reporter_id']);
            $table->dropForeign(['reviewer_id']);
        });
        $ensureIndex('diagnostic_results', 'clinical_order_item_id');
        $dropIndexes('diagnostic_results', ['diagnostic_results_same_clinic_fk', 'diagnostic_results_item_unique', 'diagnostic_results_reporter_id_foreign', 'diagnostic_results_reviewer_id_foreign']);
        Schema::table('diagnostic_results', function (Blueprint $table) {
            $table->dropColumn(['reporter_id', 'reviewer_id', 'summary', 'is_abnormal', 'review_note']);
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });

        foreach (['ordered' => 'pending', 'collected' => 'in_progress', 'resulted' => 'in_progress', 'reviewed' => 'completed'] as $from => $to) {
            DB::table('clinical_order_items')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('clinical_order_items', function (Blueprint $table) {
            $table->dropForeign('clinical_order_items_same_clinic_fk');
            $table->dropForeign(['clinic_id']);
            $table->dropForeign(['investigation_id']);
            $table->dropForeign(['collected_by']);
            $table->dropForeign(['reviewed_by']);
        });
        $ensureIndex('clinical_order_items', 'clinical_order_id');
        $dropIndexes('clinical_order_items', [
            'clinical_order_items_same_clinic_fk', 'clinical_order_items_worklist_index', 'clinical_order_items_id_clinic_unique',
            'clinical_order_items_clinic_id_foreign', 'clinical_order_items_investigation_id_foreign',
            'clinical_order_items_collected_by_foreign', 'clinical_order_items_reviewed_by_foreign',
        ]);
        Schema::table('clinical_order_items', function (Blueprint $table) {
            $table->dropColumn(['clinic_id', 'investigation_id', 'collected_by', 'reviewed_by', 'category', 'body_site', 'external_provider', 'notes', 'collected_at', 'resulted_at', 'reviewed_at', 'cancelled_at', 'cancellation_reason']);
            $table->string('status')->default('pending')->change();
        });

        Schema::table('clinical_orders', function (Blueprint $table) {
            $table->dropIndex('clinical_orders_branch_status_index');
            $table->dropUnique('clinical_orders_id_clinic_unique');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('orderer_id');
            $table->dropColumn('completed_at');
        });

        Schema::dropIfExists('clinic_investigations');
    }
};
