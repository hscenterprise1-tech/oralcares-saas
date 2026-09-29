<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;

class WorkflowDefinitionService
{
    /**
     * Create a new workflow definition.
     */
    public function createDefinition(
        Clinic $clinic,
        ?Branch $branch,
        string $name,
        ?string $description = null
    ): WorkflowDefinition {
        /*
         * The branch has to belong to the clinic.
         *
         * Nothing checked it, so a definition could be filed under one tenant
         * while pointing at another tenant's branch — and the deactivation
         * sweep below would then switch off that other tenant's active
         * workflow as a side effect of creating this one.
         */
        if ($branch && (int) $branch->clinic_id !== (int) $clinic->id) {
            throw new \InvalidArgumentException(
                "Branch {$branch->id} does not belong to clinic {$clinic->id}."
            );
        }

        return DB::transaction(function () use ($clinic, $branch, $name, $description) {
            // Deactivate existing definitions in this branch context
            if ($branch) {
                WorkflowDefinition::where('clinic_id', $clinic->id)
                    ->where('branch_id', $branch->id)
                    ->update(['is_active' => false]);
            } else {
                WorkflowDefinition::where('clinic_id', $clinic->id)
                    ->whereNull('branch_id')
                    ->update(['is_active' => false]);
            }

            return WorkflowDefinition::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch?->id,
                'name' => $name,
                'description' => $description,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Add a configurable stage/step to the workflow definition.
     */
    public function addStage(
        WorkflowDefinition $def,
        string $name,
        string $slug,
        ?string $floor = null,
        ?string $department = null,
        int $position = 0
    ): WorkflowStage {
        return WorkflowStage::create([
            'workflow_definition_id' => $def->id,
            'name' => $name,
            'slug' => $slug,
            'floor' => $floor,
            'department' => $department,
            'position' => $position,
        ]);
    }
}
