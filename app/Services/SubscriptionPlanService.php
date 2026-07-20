<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanService
{
    /**
     * Create a new subscription plan with features.
     */
    public function createPlan(array $planData, array $featureKeys): CompanyPlan
    {
        return DB::transaction(function () use ($planData, $featureKeys) {
            $plan = CompanyPlan::create($planData);

            $featureIds = CompanyFeature::whereIn('key', $featureKeys)->pluck('id')->toArray();
            $plan->features()->sync($featureIds);

            return $plan->fresh();
        });
    }

    /**
     * Update an existing plan.
     */
    public function updatePlan(CompanyPlan $plan, array $planData): CompanyPlan
    {
        $plan->update($planData);

        return $plan->fresh();
    }

    /**
     * Version a plan: clones the plan and changes its version and key, keeping old active/inactive.
     */
    public function versionPlan(CompanyPlan $plan, string $newVersion, array $overrideData = []): CompanyPlan
    {
        return DB::transaction(function () use ($plan, $newVersion, $overrideData) {
            // Keep features
            $featureIds = $plan->features()->pluck('company_features.id')->toArray();

            $newPlanData = array_merge([
                'name' => $plan->name,
                'key' => $plan->key.'_v'.str_replace('.', '_', $newVersion),
                'description' => $plan->description,
                'price' => $plan->price,
                'billing_period' => $plan->billing_period,
                'version' => $newVersion,
                'max_users' => $plan->max_users,
                'max_doctors' => $plan->max_doctors,
                'max_branches' => $plan->max_branches,
                'storage_limit_gb' => $plan->storage_limit_gb,
                'is_active' => true,
            ], $overrideData);

            // Deactivate old plan if specified
            if (isset($overrideData['deactivate_old']) && $overrideData['deactivate_old']) {
                $plan->update(['is_active' => false]);
            }

            unset($newPlanData['deactivate_old']);

            $newPlan = CompanyPlan::create($newPlanData);
            $newPlan->features()->sync($featureIds);

            return $newPlan;
        });
    }
}
