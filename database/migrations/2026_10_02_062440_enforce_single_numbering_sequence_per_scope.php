<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One numbering sequence per organisation, branch and entity — for
 * organisation-level sequences too.
 *
 * `num_seq_unique` covered (organization_id, branch_id, entity_type), but an
 * organisation-level sequence has a NULL branch and databases treat NULLs as
 * distinct, so two concurrent first registrations could create two
 * `patient` sequences for one organisation. Both then issued the same UHIDs,
 * the patients' unique UHID index refused one, and the two counters kept
 * drifting apart. A generated `branch_scope` (0 for organisation level) makes
 * the scope non-null so the unique index holds.
 *
 * Any existing duplicates are merged first: the row that has issued the most
 * numbers is kept (so no number is ever issued twice), the rest are removed
 * and logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateSequences();

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_scope')->virtualAs('coalesce(branch_id, 0)');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unique(['organization_id', 'branch_scope', 'entity_type'], 'num_seq_scope_unique');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropUnique('num_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unique(['organization_id', 'branch_id', 'entity_type'], 'num_seq_unique');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropUnique('num_seq_scope_unique');
            $table->dropColumn('branch_scope');
        });
    }

    private function mergeDuplicateSequences(): void
    {
        $groups = DB::table('numbering_sequences')
            ->selectRaw('organization_id, coalesce(branch_id, 0) as scope, entity_type, count(*) as copies')
            ->groupByRaw('organization_id, coalesce(branch_id, 0), entity_type')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('numbering_sequences')
                ->where('organization_id', $group->organization_id)
                ->whereRaw('coalesce(branch_id, 0) = ?', [$group->scope])
                ->where('entity_type', $group->entity_type)
                ->orderByDesc('current_number')
                ->orderBy('id')
                ->get();

            $removed = $rows->slice(1);
            DB::table('numbering_sequences')->whereIn('id', $removed->pluck('id'))->delete();

            Log::warning('Merged duplicate numbering sequences', [
                'kept' => (array) $rows->first(),
                'removed' => $removed->map(fn ($row) => (array) $row)->values()->all(),
            ]);
        }
    }
};
