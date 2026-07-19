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
        return DB::transaction(function () use ($clinic, $branch, $name, $description) {
            // Deactivate existing definitions in this branch context
            if ($branch) {
                WorkflowDefinition::where('branch_id', $branch->id)
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
