<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\BranchStatus;
use App\Exceptions\ResourceLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicOperatingHour;
use App\Services\ClinicContext;
use App\Services\ResourceLimitService;
use App\Services\ResourceService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ClinicContext $context, ResourceLimitService $limits): Response
    {
        $clinic = $context->clinic();
        $branches = Branch::where('clinic_id', $clinic->id)
            ->with('operatingHours')
            ->latest()
            ->get()
            ->map(fn (Branch $branch) => $this->transform($branch));
        $subscription = $limits->getActiveSubscription($clinic);

        return Inertia::render('Clinic/Admin/Infrastructure/Branches', [
            'branches' => $branches,
            'branchStatuses' => BranchStatus::cases(),
            // Surfaced up front rather than only on a failed create — the
            // admin should see they're approaching the cap, not just hit it.
            'branchLimit' => [
                'current' => $limits->getCurrentBranchCount($clinic),
                'allowed' => $subscription ? $limits->getAllowedBranches($subscription) : null,
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ClinicContext $context, ResourceLimitService $limits, ResourceService $resources): RedirectResponse
    {
        $clinic = $context->clinic();

        $validated = $this->validateBranch($request);

        try {
            $limits->enforceBranchLimit($clinic);
        } catch (ResourceLimitExceededException $e) {
            return back()->with('error', $e->getMessage());
        }

        $operatingHours = $validated['operating_hours'] ?? [];
        unset($validated['operating_hours']);

        $logo = $request->file('logo');
        unset($validated['logo']);

        $validated['clinic_id'] = $clinic->id;

        // If this branch is set as main, unset any other main branch —
        // scoped to this clinic, never another tenant's.
        if ($request->boolean('is_main')) {
            Branch::where('clinic_id', $clinic->id)->where('is_main', true)->update(['is_main' => false]);
        }

        $branch = Branch::create($validated);

        // The logo needs the branch's own id for its storage path, so it's
        // stored right after create rather than folded into $validated.
        if ($logo) {
            $branch->update(['logo_path' => $logo->store("clinics/{$clinic->id}/branches/{$branch->id}", 'public')]);
        }

        $this->syncOperatingHours($branch, $operatingHours, $resources);

        return redirect()->route('admin.branches.index')->with('success', 'Branch created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Branch $branch, ClinicContext $context, ResourceService $resources): RedirectResponse
    {
        if ($branch->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        $validated = $this->validateBranch($request, $branch);
        $operatingHours = $validated['operating_hours'] ?? [];
        unset($validated['operating_hours']);

        if ($request->hasFile('logo')) {
            if ($branch->logo_path) {
                Storage::disk('public')->delete($branch->logo_path);
            }

            $validated['logo_path'] = $request->file('logo')->store("clinics/{$context->clinic()->id}/branches/{$branch->id}", 'public');
        }
        unset($validated['logo']);

        if ($request->boolean('is_main') && ! $branch->is_main) {
            Branch::where('clinic_id', $context->clinic()->id)
                ->where('is_main', true)
                ->where('id', '!=', $branch->id)
                ->update(['is_main' => false]);
        }

        $branch->update($validated);
        $this->syncOperatingHours($branch, $operatingHours, $resources);

        return redirect()->route('admin.branches.index')->with('success', 'Branch updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch, ClinicContext $context): RedirectResponse
    {
        if ($branch->clinic_id !== $context->clinic()->id) {
            abort(403);
        }

        if ($branch->is_main) {
            return back()->with('error', 'Cannot delete the main branch. Please set another branch as main first.');
        }

        $branch->delete();

        return redirect()->route('admin.branches.index')->with('success', 'Branch deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateBranch(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('branches')->ignore($branch?->id)->where(function ($query) {
                return $query->where('organization_id', auth()->user()->organization_id);
            })],
            'status' => ['required', Rule::enum(BranchStatus::class)],
            'is_main' => ['boolean'],

            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'max:6'],

            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],

            // A planning target, not a live count of `resources` rows.
            'resource_capacity' => ['nullable', 'integer', 'min:0'],

            'gstin' => ['nullable', 'string', 'max:15'],
            'clinical_establishment_registration_number' => ['nullable', 'string', 'max:255'],

            // Overrides the clinic's own name/logo when this branch is
            // active — see `Branch::getLogoUrlAttribute()` and
            // `HandleInertiaRequests::clinicContext()` for the fallback.
            'display_name' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],

            'operating_hours' => ['nullable', 'array'],
            'operating_hours.*.day_of_week' => ['required_with:operating_hours', 'integer', 'between:0,6'],
            'operating_hours.*.open_time' => ['required_with:operating_hours', 'date_format:H:i'],
            'operating_hours.*.close_time' => ['required_with:operating_hours', 'date_format:H:i'],
            'operating_hours.*.is_closed' => ['boolean'],
        ]);
    }

    /**
     * @param  array<int, array{day_of_week: int, open_time: string, close_time: string, is_closed: bool}>  $days
     */
    protected function syncOperatingHours(Branch $branch, array $days, ResourceService $resources): void
    {
        foreach ($days as $day) {
            $resources->setOperatingHours(
                $branch,
                (int) $day['day_of_week'],
                $day['open_time'],
                $day['close_time'],
                (bool) ($day['is_closed'] ?? false)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function transform(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'clinic_id' => $branch->clinic_id,
            'organization_id' => $branch->organization_id,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'status' => $branch->status->value,
            'is_main' => $branch->is_main,
            'address_line1' => $branch->address_line1,
            'address_line2' => $branch->address_line2,
            'city' => $branch->city,
            'state' => $branch->state,
            'pincode' => $branch->pincode,
            'phone' => $branch->phone,
            'whatsapp_number' => $branch->whatsapp_number,
            'resource_capacity' => $branch->resource_capacity,
            'gstin' => $branch->gstin,
            'clinical_establishment_registration_number' => $branch->clinical_establishment_registration_number,
            'display_name' => $branch->display_name,
            'logo_url' => $branch->logo_url,
            'operating_hours' => $branch->operatingHours->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'open_time' => Carbon::parse($hour->open_time)->format('H:i'),
                'close_time' => Carbon::parse($hour->close_time)->format('H:i'),
                'is_closed' => $hour->is_closed,
            ])->values(),
            'today_hours_label' => $this->todayHoursLabel($branch->operatingHours),
            'created_at' => $branch->created_at,
            'updated_at' => $branch->updated_at,
        ];
    }

    /**
     * @param  Collection<int, ClinicOperatingHour>  $hours
     */
    protected function todayHoursLabel(Collection $hours): string
    {
        // Same convention `ScheduleValidationService` reads bookings
        // against: PHP's `date('w')`, 0 = Sunday.
        $todayIndex = (int) now()->format('w');
        $today = $hours->firstWhere('day_of_week', $todayIndex);

        if (! $today) {
            return 'Hours not set';
        }

        if ($today->is_closed) {
            return 'Closed today';
        }

        return Carbon::parse($today->open_time)->format('g:i A').' – '.Carbon::parse($today->close_time)->format('g:i A');
    }
}
