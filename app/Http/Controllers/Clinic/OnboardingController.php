<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    /**
     * Display the onboarding wizard.
     */
    public function index()
    {
        $user = Auth::user();

        $progress = OnboardingProgress::where('organization_id', $user->organization_id)->first();

        if (! $progress) {
            abort(404, 'Onboarding record not found.');
        }

        if ($progress->status === 'completed') {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Clinic/Onboarding/Index', [
            'progress' => $progress,
            'organization' => $user->organization,
        ]);
    }

    /**
     * Update the onboarding progress.
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        $progress = OnboardingProgress::where('organization_id', $user->organization_id)->firstOrFail();

        $validated = $request->validate([
            'step' => ['required', 'string'],
            'data' => ['nullable', 'array'],
        ]);

        $step = $validated['step'];

        // Normally we would save step-specific data here.
        // For Phase 1, we simulate progression.

        $completedSteps = $progress->completed_steps ?? [];
        if (! in_array($progress->current_step, $completedSteps)) {
            $completedSteps[] = $progress->current_step;
        }

        $progress->update([
            'current_step' => $step,
            'completed_steps' => array_values(array_unique($completedSteps)),
        ]);

        return redirect()->back();
    }

    /**
     * Complete the onboarding process.
     */
    public function complete(Request $request)
    {
        $user = Auth::user();
        $progress = OnboardingProgress::where('organization_id', $user->organization_id)->firstOrFail();

        $completedSteps = $progress->completed_steps ?? [];
        if (! in_array($progress->current_step, $completedSteps)) {
            $completedSteps[] = $progress->current_step;
        }

        $progress->update([
            'current_step' => 'completed',
            'status' => 'completed',
            'completed_steps' => array_values(array_unique($completedSteps)),
        ]);

        return redirect()->route('dashboard');
    }
}
