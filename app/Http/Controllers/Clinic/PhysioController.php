<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\Consultation\PhysioAssessmentRequest;
use App\Http\Requests\Clinic\Consultation\PhysioCarePlanRequest;
use App\Http\Requests\Clinic\Consultation\PhysioSessionRequest;
use App\Http\Requests\Clinic\SellSessionPackageRequest;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientSessionPackage;
use App\Models\PhysioCarePlan;
use App\Models\PhysioSession;
use App\Services\ClinicContext;
use App\Services\Physio\PhysioRecord;
use App\Services\Reception\IntakeSubmissions;
use App\Support\Specialty\SpecialtyRegistry;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The physiotherapy extensions inside a session (consultation): assessment,
 * plan of care and exercises, SOAP notes with package punching; and selling
 * a session package from the patient's record. Each action needs the branch
 * to practise a specialty that brings the relevant extension.
 */
class PhysioController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PhysioRecord $physio,
    ) {}

    public function assessment(PhysioAssessmentRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, 'physio_assessment']);

        return $this->attempt(function () use ($request, $encounter) {
            $this->physio->saveAssessment($encounter, $request->validated(), $this->therapist());

            return back()->with('success', 'Assessment saved.');
        });
    }

    public function session(PhysioSessionRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, 'soap_notes']);
        if ($request->filled('package_id')) {
            $this->authorize('writeExtension', [$encounter, 'session_packages']);
        }

        return $this->attempt(function () use ($request, $encounter) {
            $session = $this->physio->recordSession($encounter, $request->validated(), $this->therapist(), $request->user());

            return back()->with('success', $session->status === PhysioSession::COMPLETED ? "Session {$session->session_number} completed." : 'Session note saved.');
        });
    }

    public function startPlan(PhysioCarePlanRequest $request, ClinicalEncounter $encounter): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, 'exercise_plan']);

        return $this->attempt(function () use ($request, $encounter) {
            $this->physio->startCarePlan($encounter, $request->safe()->except('exercises'), $request->validated('exercises') ?? [], $this->therapist(), $request->user());

            return back()->with('success', 'Plan of care started.');
        });
    }

    public function exercises(PhysioCarePlanRequest $request, ClinicalEncounter $encounter, PhysioCarePlan $plan): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, 'exercise_plan']);
        $this->assertSamePatient($encounter, $plan->clinic_id, $plan->patient_id);

        return $this->attempt(function () use ($request, $plan) {
            $this->physio->replaceExercises($plan, $request->validated('exercises') ?? [], $this->therapist(), $request->user());

            return back()->with('success', 'Exercise programme revised.');
        });
    }

    public function closePlan(Request $request, ClinicalEncounter $encounter, PhysioCarePlan $plan): RedirectResponse
    {
        $this->authorize('writeExtension', [$encounter, 'exercise_plan']);
        $this->assertSamePatient($encounter, $plan->clinic_id, $plan->patient_id);
        $validated = $request->validate([
            'status' => ['required', Rule::in([PhysioCarePlan::COMPLETED, PhysioCarePlan::DISCONTINUED])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(function () use ($plan, $validated) {
            $this->physio->closeCarePlan($plan, $validated['status'], $validated['note'] ?? null);

            return back()->with('success', 'Plan of care closed.');
        });
    }

    /**
     * Sell a package (and bill it, when it has a price). A request key made
     * when the form opened sells it once however often it is sent; another
     * package is another key.
     */
    public function sellPackage(SellSessionPackageRequest $request, Patient $patient, SpecialtyRegistry $specialties, IntakeSubmissions $submissions): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        abort_unless($patient->clinic_id === $clinic->id, 404);
        $this->authorize('view', $patient);
        abort_unless($request->user()->hasClinicPermission(ClinicPermission::BillingCreate), 403);
        $branch = $this->clinicContext->requireBranch();
        abort_unless(in_array('session_packages', $specialties->extensionsFor($specialties->forBranch($branch, $clinic)), true), 403);

        // By reference: the key is kept only when the package was sold.
        $sold = false;
        $take = function () use ($request, $patient, $branch, &$sold): RedirectResponse {
            return $this->attempt(function () use ($request, $patient, $branch, &$sold) {
                $package = $this->physio->sellPackage($patient, $branch, $request->safe()->except('idempotency_key'), $this->clinicContext->currentEmployee() ?? abort(403));
                $sold = true;

                return back()->with('success', "{$package->name} sold.");
            });
        };

        $key = $request->validated('idempotency_key');
        if ($key === null) {
            $submissions->withoutKey($clinic, 'physio.package.sell');

            return $take();
        }

        return $submissions->onceRedirect($clinic, $request->user(), $key, "pkg-p:{$patient->id}", $take, function () use (&$sold): bool {
            return $sold;
        }, 'This package was already sold; it was not sold twice.', atomic: true);
    }

    public function cancelPackage(Request $request, Patient $patient, PatientSessionPackage $package): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        abort_unless($patient->clinic_id === $clinic->id && $package->patient_id === $patient->id, 404);
        $this->authorize('view', $patient);
        abort_unless($request->user()->hasClinicPermission(ClinicPermission::BillingCreate), 403);

        return $this->attempt(function () use ($package) {
            $this->physio->cancelPackage($package);

            return back()->with('success', 'Package cancelled.');
        });
    }

    private function assertSamePatient(ClinicalEncounter $encounter, int $clinicId, int $patientId): void
    {
        abort_unless($clinicId === $encounter->clinic_id && $patientId === $encounter->patient_id, 404);
    }

    private function therapist(): ClinicEmployee
    {
        return $this->clinicContext->currentEmployee() ?? abort(403);
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['physio' => $e->getMessage()]);
        }
    }
}
