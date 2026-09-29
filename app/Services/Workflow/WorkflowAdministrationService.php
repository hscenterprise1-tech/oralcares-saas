<?php

namespace App\Services\Workflow;

use App\Events\WorkflowCancelled;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowStageChanged;
use App\Events\WorkflowStarted;
use App\Models\Branch;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowHistory;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * What the workflow engine has been configured to do, seen from HQ.
 *
 * Read-only. The engine in `App\Services\Workflow*` is the authoritative one and
 * is reused as it stands; nothing here starts, moves, completes or cancels a
 * run, and no second engine is introduced.
 *
 * The engine is clinic-scoped by schema — `workflow_definitions.clinic_id`,
 * `workflow_runs.encounter_id`, `workflow_histories.performed_by` pointing at
 * `clinic_employees` — so HQ's role here is oversight across tenants, not
 * authorship. Whether HQ should be able to author a tenant's workflow is a
 * business decision, and no write path is offered until it is made.
 *
 * Everything reported is derived from the rows. The one thing deliberately not
 * derived is "stuck": how long a case may sit in a stage before it is a problem
 * differs per stage and per clinic, and no threshold exists in this application.
 * Runs are ordered by how long they have been still, and the reader judges.
 */
class WorkflowAdministrationService
{
    /**
     * Every definition across tenants, with what it is made of and how it runs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(?string $search = null): array
    {
        $definitions = WorkflowDefinition::query()
            ->when($search, fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->withCount(['stages', 'transitions'])
            ->orderByDesc('is_active')
            ->orderBy('clinic_id')
            ->orderBy('name')
            ->limit(200)
            ->get();

        if ($definitions->isEmpty()) {
            return [];
        }

        $runCounts = WorkflowRun::query()
            ->whereIn('workflow_definition_id', $definitions->pluck('id'))
            ->select('workflow_definition_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('workflow_definition_id', 'status')
            ->get()
            ->groupBy('workflow_definition_id');

        $clinics = $this->clinicNames($definitions->pluck('clinic_id'));
        $branches = $this->branchNames($definitions->pluck('branch_id')->filter());

        return $definitions->map(function (WorkflowDefinition $definition) use ($runCounts, $clinics, $branches): array {
            $runs = ($runCounts[$definition->id] ?? collect())
                ->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->aggregate])
                ->all();

            return [
                'id' => $definition->id,
                'name' => $definition->name,
                'description' => $definition->description,
                'version' => $definition->version,
                'is_active' => (bool) $definition->is_active,
                'clinic_id' => $definition->clinic_id,
                'clinic' => $clinics[$definition->clinic_id] ?? null,
                'branch_id' => $definition->branch_id,
                'branch' => $definition->branch_id ? ($branches[$definition->branch_id] ?? null) : null,
                'stages' => (int) $definition->stages_count,
                'transitions' => (int) $definition->transitions_count,
                'runs' => $runs,
                'runs_total' => array_sum($runs),
                'created_at' => $definition->created_at?->toDateTimeString(),
            ];
        })->all();
    }

    /**
     * Configurations that cannot do what they look like they do.
     *
     * Each finding is a fact about the rows, not a judgement about how a clinic
     * should run its floor.
     *
     * @return array<string, mixed>
     */
    public function integrity(): array
    {
        $stages = WorkflowStage::query()
            ->orderBy('workflow_definition_id')
            ->orderBy('position')
            ->get(['id', 'workflow_definition_id', 'name', 'position']);

        $transitions = WorkflowTransition::query()
            ->get(['id', 'workflow_definition_id', 'from_stage_id', 'to_stage_id', 'validation_rules']);

        $stagesByDefinition = $stages->groupBy('workflow_definition_id');
        $definitions = WorkflowDefinition::query()->get(['id', 'name', 'clinic_id', 'branch_id', 'is_active']);
        $definitionNames = $definitions->pluck('name', 'id');

        $inbound = $transitions->pluck('to_stage_id')->filter()->unique()->flip();
        $outbound = $transitions->pluck('from_stage_id')->filter()->unique()->flip();

        $noStages = [];
        $noTransitions = [];
        $unreachable = [];
        $deadEnds = [];

        foreach ($definitions as $definition) {
            $own = $stagesByDefinition[$definition->id] ?? collect();

            if ($own->isEmpty()) {
                /* The engine refuses to start these — there is no first stage. */
                $noStages[] = $this->definitionRef($definition);

                continue;
            }

            $ownTransitions = $transitions->where('workflow_definition_id', $definition->id);

            if ($own->count() > 1 && $ownTransitions->isEmpty()) {
                /*
                 * A run starts here and can never leave the first stage.
                 *
                 * Reported once and then skipped: with no transitions at all,
                 * every stage is both unreachable and a dead end, and listing
                 * each of them would report the same single break three times.
                 */
                $noTransitions[] = $this->definitionRef($definition);

                continue;
            }

            $first = $own->first();
            $last = $own->last();

            foreach ($own as $stage) {
                if ($stage->id !== $first->id && ! $inbound->has($stage->id)) {
                    $unreachable[] = [
                        'stage' => $stage->name,
                        'definition' => $definitionNames[$definition->id] ?? null,
                        'definition_id' => $definition->id,
                    ];
                }

                if ($stage->id !== $last->id && ! $outbound->has($stage->id)) {
                    $deadEnds[] = [
                        'stage' => $stage->name,
                        'definition' => $definitionNames[$definition->id] ?? null,
                        'definition_id' => $definition->id,
                    ];
                }
            }
        }

        /*
         * `validation_rules` is written by `createTransition` and read by
         * nothing: `canTransition` evaluates `rules` only. Anyone who set these
         * believing they gate a movement is wrong, and no one has been told.
         */
        $unenforced = $transitions
            ->filter(fn ($transition) => ! empty($transition->validation_rules))
            ->map(fn ($transition) => [
                'transition_id' => $transition->id,
                'definition' => $definitionNames[$transition->workflow_definition_id] ?? null,
                'definition_id' => $transition->workflow_definition_id,
                'rules' => $transition->validation_rules,
            ])
            ->values()
            ->all();

        $duplicateActive = $this->duplicateActive($definitions);
        $foreignBranch = $this->foreignBranch($definitions);

        return [
            'no_stages' => $noStages,
            'no_transitions' => $noTransitions,
            'unreachable_stages' => $unreachable,
            'dead_end_stages' => $deadEnds,
            'unenforced_validation_rules' => $unenforced,
            'duplicate_active' => $duplicateActive,
            'foreign_branch' => $foreignBranch,
            /* Every finding the page renders, so the count and the page agree. */
            'total' => count($noStages) + count($noTransitions) + count($unreachable)
                + count($deadEnds) + count($unenforced) + count($duplicateActive)
                + count($foreignBranch),
        ];
    }

    /**
     * Runs by status, and the active ones ordered by how long they have been
     * still.
     *
     * @return array<string, mixed>
     */
    public function runs(int $limit = 25): array
    {
        $byStatus = WorkflowRun::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $active = WorkflowRun::query()
            ->where('status', 'active')
            ->with(['currentStage:id,name', 'workflowDefinition:id,name,clinic_id'])
            ->latest('id')
            ->limit(500)
            ->get();

        $lastMovement = WorkflowHistory::query()
            ->whereIn('workflow_run_id', $active->pluck('id'))
            ->select('workflow_run_id', DB::raw('max(created_at) as moved_at'))
            ->groupBy('workflow_run_id')
            ->pluck('moved_at', 'workflow_run_id');

        $clinics = $this->clinicNames($active->pluck('workflowDefinition.clinic_id')->filter());

        $rows = $active
            ->map(function (WorkflowRun $run) use ($lastMovement, $clinics): array {
                $movedAt = $lastMovement[$run->id] ?? $run->created_at;
                $movedAt = $movedAt ? Carbon::parse($movedAt) : null;

                return [
                    'id' => $run->id,
                    'encounter_id' => $run->encounter_id,
                    'definition' => $run->workflowDefinition?->name,
                    'definition_id' => $run->workflow_definition_id,
                    'clinic' => $clinics[$run->workflowDefinition?->clinic_id] ?? null,
                    'stage' => $run->currentStage?->name,
                    /*
                     * How long it has been still. Not "stuck": no threshold for
                     * that exists anywhere in this application, and picking one
                     * here would be inventing a clinical policy.
                     */
                    'still_for_hours' => $movedAt ? (int) $movedAt->diffInHours(now()) : null,
                    'last_moved_at' => $movedAt?->toDateTimeString(),
                    'started_at' => $run->created_at?->toDateTimeString(),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['still_for_hours'] ?? -1)
            ->take($limit)
            ->values()
            ->all();

        return [
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
            'longest_still' => $rows,
        ];
    }

    /**
     * The most recent movements, whichever tenant they belong to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 40): array
    {
        return WorkflowHistory::query()
            ->with(['fromStage:id,name', 'toStage:id,name', 'performer:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (WorkflowHistory $entry): array => [
                'id' => $entry->id,
                'run_id' => $entry->workflow_run_id,
                'action' => $entry->action_type?->value,
                'from' => $entry->fromStage?->name,
                'to' => $entry->toStage?->name,
                'performer' => $entry->performer?->name,
                'reason' => $entry->reason,
                'at' => (string) $entry->created_at,
            ])
            ->all();
    }

    /**
     * What the engine does not do, counted rather than claimed.
     *
     * @return array<string, mixed>
     */
    public function unsupported(): array
    {
        return [
            /*
             * The engine writes exactly three: active, completed, cancelled.
             * There is no failed state, no error column and no attempt counter,
             * so a run that cannot proceed is indistinguishable from one waiting
             * on a person — and nothing can be retried, because nothing records
             * having failed.
             */
            'statuses_in_use' => WorkflowRun::query()
                ->select('status')
                ->distinct()
                ->pluck('status')
                ->all(),
            'has_failure_state' => WorkflowRun::query()->where('status', 'failed')->exists(),
            /*
             * All four events are dispatched and nothing subscribes, so
             * completing a workflow notifies nobody. Counted at runtime so this
             * stops saying it the moment a listener is registered.
             */
            'event_listeners' => collect([
                'WorkflowStarted' => WorkflowStarted::class,
                'WorkflowStageChanged' => WorkflowStageChanged::class,
                'WorkflowCompleted' => WorkflowCompleted::class,
                'WorkflowCancelled' => WorkflowCancelled::class,
            ])->map(fn (string $event) => count(Event::getListeners($event)))->all(),
            /*
             * `workflow_histories.performed_by` is a foreign key to
             * `clinic_employees`, so an HQ user acting on a run cannot be
             * recorded as having done it.
             */
            'performer_scope' => 'clinic_employees',
        ];
    }

    /**
     * More than one active definition for the same branch.
     *
     * `createDefinition` deactivates the others as it creates one, so this only
     * arises from a write that did not go through the service.
     *
     * @param  Collection<int, WorkflowDefinition>  $definitions
     * @return array<int, array<string, mixed>>
     */
    protected function duplicateActive($definitions): array
    {
        return $definitions
            ->where('is_active', true)
            ->groupBy(fn (WorkflowDefinition $definition) => $definition->clinic_id.':'.($definition->branch_id ?? 'clinic'))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => [
                'clinic_id' => $group->first()->clinic_id,
                'branch_id' => $group->first()->branch_id,
                'names' => $group->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Definitions filed under one clinic but pointing at another's branch.
     *
     * The service now refuses to create these; existing rows are still here.
     *
     * @param  Collection<int, WorkflowDefinition>  $definitions
     * @return array<int, array<string, mixed>>
     */
    protected function foreignBranch($definitions): array
    {
        $branchClinics = Branch::withoutOrganizationScope()
            ->whereIn('id', $definitions->pluck('branch_id')->filter())
            ->pluck('clinic_id', 'id');

        return $definitions
            ->filter(fn (WorkflowDefinition $definition) => $definition->branch_id !== null
                && isset($branchClinics[$definition->branch_id])
                && (int) $branchClinics[$definition->branch_id] !== (int) $definition->clinic_id)
            ->map(fn (WorkflowDefinition $definition) => [
                'definition_id' => $definition->id,
                'name' => $definition->name,
                'clinic_id' => $definition->clinic_id,
                'branch_id' => $definition->branch_id,
                'branch_clinic_id' => (int) $branchClinics[$definition->branch_id],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function definitionRef(WorkflowDefinition $definition): array
    {
        return [
            'definition_id' => $definition->id,
            'name' => $definition->name,
            'clinic_id' => $definition->clinic_id,
            'is_active' => (bool) $definition->is_active,
        ];
    }

    /** @return Collection<int, string> */
    protected function clinicNames($ids)
    {
        return DB::table('clinics')->whereIn('id', $ids->unique()->values())->pluck('name', 'id');
    }

    /** @return Collection<int, string> */
    protected function branchNames($ids)
    {
        return DB::table('branches')->whereIn('id', $ids->unique()->values())->pluck('name', 'id');
    }
}
