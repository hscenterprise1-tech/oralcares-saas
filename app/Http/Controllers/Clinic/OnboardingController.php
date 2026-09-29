<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use App\Models\OnboardingTemplateStep;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic's view of its setup checklist.
 *
 * Onboarding is complete when the organisation's `onboarding_progress` row is
 * `completed`. `EnsureOnboardingCompleted` keeps the rest of the portal behind
 * that. The steps come from the onboarding template for the clinic type, the
 * same list Customer Success works from. Either side can mark a step done, and
 * only a clinic user with `settings.manage` can do so here.
 */
class OnboardingController extends Controller
{
    public function __construct(protected ClinicContext $clinicContext) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $progress = OnboardingProgress::forClinicUser($request->user(), $this->clinicContext->clinic());

        if (! $progress || $progress->status === 'completed') {
            return redirect()->route('dashboard');
        }

        $done = $progress->completed_steps ?? [];

        return Inertia::render('Clinic/Onboarding/Index', [
            'clinicName' => $this->clinicContext->clinic()?->name,
            'status' => $progress->status,
            'currentStep' => $progress->current_step,
            'steps' => $progress->steps()->values()->map(fn (OnboardingTemplateStep $step) => [
                'key' => $step->step_key,
                'label' => $step->label,
                'description' => $step->description,
                'required' => (bool) $step->is_required,
                'done' => in_array($step->step_key, $done, true),
            ]),
            'canFinish' => $progress->hasCompletedRequiredSteps(),
            'canManage' => $request->user()->hasClinicPermission(ClinicPermission::SettingsManage),
        ]);
    }

    /**
     * Mark one checklist step as done.
     */
    public function update(Request $request): RedirectResponse
    {
        $progress = $this->pendingProgress($request);

        $validated = $request->validate([
            'step' => ['required', 'string', Rule::in($progress->steps()->pluck('step_key')->all())],
        ]);

        DB::transaction(function () use ($progress, $validated) {
            $progress = OnboardingProgress::query()->lockForUpdate()->findOrFail($progress->id);

            $done = array_values(array_unique([...($progress->completed_steps ?? []), $validated['step']]));
            $next = $progress->steps()->first(fn (OnboardingTemplateStep $step) => ! in_array($step->step_key, $done, true));

            $progress->update([
                'completed_steps' => $done,
                'current_step' => $next?->step_key ?? $validated['step'],
                'status' => 'in_progress',
            ]);
        });

        return back()->with('success', 'Step marked as done.');
    }

    /**
     * Finish onboarding once every required step is done.
     */
    public function complete(Request $request): RedirectResponse
    {
        $progress = $this->pendingProgress($request);

        if (! $progress->hasCompletedRequiredSteps()) {
            throw ValidationException::withMessages([
                'step' => 'Finish every required step before completing setup.',
            ]);
        }

        $progress->update(['status' => 'completed', 'current_step' => 'completed']);

        return redirect()->route('dashboard');
    }

    private function pendingProgress(Request $request): OnboardingProgress
    {
        $progress = OnboardingProgress::forClinicUser($request->user(), $this->clinicContext->clinic());

        abort_if(! $progress || $progress->status === 'completed', 404);

        return $progress;
    }
}
