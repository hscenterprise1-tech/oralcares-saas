<?php

namespace App\Http\Controllers\Master;

use App\Data\ClinicOnboardingData;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\User;
use App\Services\OnboardClinicService;
use App\UserType;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClinicController extends Controller
{
    /**
     * Display a listing of clinics.
     */
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Clinic::query()
            ->with(['primaryBranch', 'primaryOwnerMembership.user'])
            ->withCount('branches')
            ->latest();

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! empty($status)) {
            $query->where('status', $status);
        }

        $clinics = $query->paginate(10)->withQueryString();

        return view('master.clinics.index', compact('clinics', 'search', 'status'));
    }

    /**
     * Show the form for creating a new clinic.
     */
    public function create(): View
    {
        $eligibleOwners = User::where('user_type', UserType::ClinicUser)->get();

        return view('master.clinics.create', compact('eligibleOwners'));
    }

    /**
     * Store a newly created clinic in storage.
     */
    public function store(Request $request, OnboardClinicService $onboardService): RedirectResponse
    {
        $validated = $request->validate([
            'clinic_name' => ['required', 'string', 'max:255'],
            'clinic_slug' => ['required', 'string', 'max:255', 'unique:clinics,slug'],
            'owner_id' => ['required', 'exists:users,id'],
            'primary_branch_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $owner = User::findOrFail($validated['owner_id']);

            $data = new ClinicOnboardingData(
                clinicName: $validated['clinic_name'],
                clinicSlug: $validated['clinic_slug'],
                owner: $owner,
                primaryBranchName: $validated['primary_branch_name']
            );

            $result = $onboardService->execute($data);

            return redirect()
                ->route('master.clinics.show', $result->clinic)
                ->with('success', 'Clinic onboarded successfully.');
        } catch (Exception $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified clinic details.
     */
    public function show(Clinic $clinic): View
    {
        $clinic->load([
            'primaryBranch',
            'primaryOwnerMembership.user',
            'roles',
        ]);

        return view('master.clinics.show', compact('clinic'));
    }
}
