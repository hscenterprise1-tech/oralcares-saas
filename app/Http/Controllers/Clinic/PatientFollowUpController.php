<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Services\ClinicContext;
use App\Services\Patient\FollowUps;
use App\Services\Patient\Patient360\Patient360Access;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Follow-ups from Patient 360: add, reschedule, complete, cancel. Clinicians
 * and the front desk manage them; each follow-up is found through the
 * patient and clinic, so another patient's id is a 404.
 */
class PatientFollowUpController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected Patient360Access $access,
        protected FollowUps $followUps,
    ) {}

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizeFor($request, $patient);
        $validated = $request->validate([
            'due_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
            'practitioner_id' => ['nullable', 'integer'],
        ]);
        $practitioner = isset($validated['practitioner_id'])
            ? ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->find($validated['practitioner_id']) ?? throw ValidationException::withMessages(['practitioner_id' => 'Choose a practitioner of this clinic.'])
            : null;

        return $this->attempt(function () use ($patient, $validated, $practitioner) {
            $this->followUps->add($patient, $validated['due_on'], $validated['reason'] ?? null, $practitioner, $this->clinicContext->workingBranch()?->id);

            return back()->with('success', 'Follow-up added.');
        });
    }

    public function update(Request $request, Patient $patient, int $followUp): RedirectResponse
    {
        $record = $this->find($request, $patient, $followUp);
        $validated = $request->validate(['due_on' => ['required', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($record, $validated) {
            $this->followUps->reschedule($record, $validated['due_on']);

            return back()->with('success', 'Follow-up moved.');
        });
    }

    public function complete(Request $request, Patient $patient, int $followUp): RedirectResponse
    {
        $record = $this->find($request, $patient, $followUp);

        return $this->attempt(function () use ($record) {
            $this->followUps->complete($record);

            return back()->with('success', 'Follow-up completed.');
        });
    }

    public function cancel(Request $request, Patient $patient, int $followUp): RedirectResponse
    {
        $record = $this->find($request, $patient, $followUp);

        return $this->attempt(function () use ($record) {
            $this->followUps->cancel($record);

            return back()->with('success', 'Follow-up cancelled.');
        });
    }

    private function authorizeFor(Request $request, Patient $patient): void
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        abort_unless($this->access->canManageFollowUps($request->user()), 403);
    }

    private function find(Request $request, Patient $patient, int $id): PatientFollowUp
    {
        $this->authorizeFor($request, $patient);

        return PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->findOrFail($id);
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['follow_up' => $e->getMessage()]);
        }
    }
}
