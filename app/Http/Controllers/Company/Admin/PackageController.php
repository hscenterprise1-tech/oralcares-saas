<?php

namespace App\Http\Controllers\Company\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PackageController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('crm.packages.view');

        $plans = CompanyPlan::query()
            ->withCount(['subscriptions', 'trials'])
            ->with('features:id,key,name')
            ->orderBy('price')
            ->get()
            ->map(fn (CompanyPlan $plan) => $this->transform($plan));

        return Inertia::render('Company/Admin/Packages/Index', [
            'packages' => $plans,
            'features' => CompanyFeature::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'description']),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('crm.packages.manage');

        $validated = $this->validatePackage($request);
        $moduleKeys = $validated['module_keys'] ?? [];
        unset($validated['module_keys']);

        $plan = CompanyPlan::create($validated + [
            'key' => str($validated['name'])->slug().'-'.str()->random(4),
            'version' => 1,
        ]);

        $plan->features()->sync(CompanyFeature::whereIn('key', $moduleKeys)->pluck('id'));

        return back()->with('success', "Package \"{$plan->name}\" created.");
    }

    public function update(Request $request, CompanyPlan $package)
    {
        Gate::authorize('crm.packages.manage');

        $validated = $this->validatePackage($request);
        $moduleKeys = $validated['module_keys'] ?? [];
        unset($validated['module_keys']);

        $package->update($validated);
        $package->features()->sync(CompanyFeature::whereIn('key', $moduleKeys)->pluck('id'));

        return back()->with('success', "Package \"{$package->name}\" updated.");
    }

    public function destroy(CompanyPlan $package)
    {
        Gate::authorize('crm.packages.manage');

        if ($package->subscriptions()->exists()) {
            return back()->with('error', 'Cannot delete a package with active subscriptions. Deactivate it instead.');
        }

        $package->delete();

        return back()->with('success', 'Package removed.');
    }

    protected function validatePackage(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_period' => ['required', 'string', 'in:monthly,yearly'],
            'max_users' => ['required', 'integer', 'min:1'],
            'additional_user_price' => ['nullable', 'numeric', 'min:0'],
            'max_doctors' => ['required', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:0'],
            'max_branches' => ['required', 'integer', 'min:1'],
            'storage_limit_gb' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'is_trial_available' => ['sometimes', 'boolean'],
            'trial_duration_days' => ['required_if:is_trial_available,true', 'nullable', 'integer', 'min:1', 'max:90'],
            'module_keys' => ['sometimes', 'array'],
            'module_keys.*' => ['string', 'exists:company_features,key'],
        ]);
    }

    protected function transform(CompanyPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => (float) $plan->price,
            'billing_period' => $plan->billing_period,
            'max_users' => $plan->max_users,
            'additional_user_price' => $plan->additional_user_price !== null ? (float) $plan->additional_user_price : null,
            'max_doctors' => $plan->max_doctors,
            'max_staff' => $plan->max_staff,
            'max_branches' => $plan->max_branches,
            'storage_limit_gb' => $plan->storage_limit_gb,
            'is_active' => $plan->is_active,
            'is_trial_available' => $plan->is_trial_available,
            'trial_duration_days' => $plan->trial_duration_days,
            'subscriptions_count' => $plan->subscriptions_count ?? 0,
            'module_keys' => $plan->features->pluck('key')->all(),
        ];
    }
}
