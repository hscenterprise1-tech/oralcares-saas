<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use Illuminate\Support\Facades\DB;

class PlanService
{
    /**
     * Create a subscription plan linked to specific catalog features.
     */
    public function createPlan(array $planData, array $featureKeys): CompanyPlan
    {
        return DB::transaction(function () use ($planData, $featureKeys) {
            $plan = CompanyPlan::create($planData);

            $featureIds = CompanyFeature::whereIn('key', $featureKeys)->pluck('id')->toArray();
            $plan->features()->sync($featureIds);

            $plan->refresh();

            return $plan;
        });
    }
}
